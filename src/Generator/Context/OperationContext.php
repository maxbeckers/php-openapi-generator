<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Generator\Context;

use MaxBeckers\OpenApiGenerator\Generator\HttpStatus;
use MaxBeckers\OpenApiGenerator\Spec\Operation;

/**
 * All information about a single API operation needed by server/client templates.
 */
readonly class OperationContext
{
    /**
     * @param string              $operationId  Cleaned PHP method name (camelCase)
     * @param string              $httpMethod   Lowercase HTTP verb
     * @param string              $path         OAS path string, e.g. /pets/{petId}
     * @param string              $phpPath      Path as PHP string literal with {$param} placeholders for interpolation
     * @param ParameterContext[]  $pathParams   Path parameters
     * @param ParameterContext[]  $queryParams  Query parameters
     * @param ParameterContext[]  $headerParams Header parameters
     * @param ParameterContext[]  $cookieParams Cookie parameters
     * @param string|null         $requestBodyType FQCN or scalar type string, null if no body
     * @param bool                $requestBodyRequired
     * @param string              $returnType   PHP return type (e.g. 'PetDto', 'PetDto[]', 'void')
     * @param bool                $returnsArray Whether the primary return type is an array of models
     * @param string|null         $summary
     * @param string|null         $description
     * @param bool                $deprecated
     * @param string[]            $tags
     * @param string[]            $successCodes HTTP success status codes e.g. ['200', '201']
     * @param string[]            $errorCodes   HTTP error status codes
     * @param array<string, string|null> $successResponses Success status code (or 'default') => PHP type, null for no content
     * @param string[]            $returnTypes  Distinct non-null success payload types
     * @param bool                $returnsNullable Whether some success responses have no content while others do
     * @param array<string, string|null> $errorResponses Error status code ('404', '4XX', 'default') => model FQCN, null for no model
     * @param list<list<string>>|null    $security Alternative security requirements (scheme names), null when not secured
     */
    public function __construct(
        public string $operationId,
        public string $httpMethod,
        public string $path,
        public string $phpPath,
        public array $pathParams,
        public array $queryParams,
        public array $headerParams,
        public array $cookieParams,
        public ?string $requestBodyType,
        public bool $requestBodyRequired,
        public string $returnType,
        public bool $returnsArray,
        public ?string $summary,
        public ?string $description,
        public bool $deprecated,
        public array $tags,
        public array $successCodes,
        public array $errorCodes,
        public Operation $operation,
        public array $successResponses = [],
        public array $returnTypes = [],
        public bool $returnsNullable = false,
        public array $errorResponses = [],
        public ?array $security = null,
    ) {
    }

    /**
     * Numeric 4xx/5xx status codes mapped to their generated exception class names.
     *
     * @return array<int, string>
     */
    public function errorExceptionClasses(): array
    {
        $classes = [];
        foreach (array_keys($this->errorResponses) as $code) {
            if (HttpStatus::isErrorCode((string) $code)) {
                $classes[(int) $code] = HttpStatus::exceptionClassName((int) $code);
            }
        }
        ksort($classes);

        return $classes;
    }

    /**
     * PHP match-arm conditions (on $statusCode) mapped to the short model name of the documented JSON error payload,
     * ordered from the most to the least specific status key.
     *
     * @return list<array{string, string}>
     */
    public function errorModelArms(): array
    {
        $exact = [];
        $ranges = [];
        $default = [];
        foreach ($this->errorResponses as $code => $type) {
            if ($type === null) {
                continue;
            }
            $code = (string) $code;
            $shortType = self::shortType($type);
            if (ctype_digit($code)) {
                $exact[] = ['$statusCode === ' . (int) $code, $shortType];
            } elseif (preg_match('/^([1-5])XX$/i', $code, $matches) === 1) {
                $lower = (int) $matches[1] * 100;
                $ranges[] = ['$statusCode >= ' . $lower . ' && $statusCode < ' . ($lower + 100), $shortType];
            } elseif ($code === 'default') {
                $default[] = ['true', $shortType];
            }
        }

        return [...$exact, ...$ranges, ...$default];
    }

    /**
     * PHP return type for method signatures, using short class names.
     */
    public function signatureReturnType(): string
    {
        if ($this->returnType === 'void' || $this->returnType === 'mixed') {
            return $this->returnType;
        }

        if ($this->returnsArray) {
            return ($this->returnsNullable ? '?' : '') . 'array';
        }

        $types = array_map(
            static fn (string $type): string => self::shortType($type),
            $this->returnTypes !== [] ? $this->returnTypes : [$this->returnType],
        );

        if (count($types) === 1) {
            return ($this->returnsNullable ? '?' : '') . $types[0];
        }

        return implode('|', $types) . ($this->returnsNullable ? '|null' : '');
    }

    /**
     * Status codes whose success response has no content.
     *
     * @return list<int>
     */
    public function emptySuccessCodes(): array
    {
        $codes = [];
        foreach ($this->successResponses as $code => $type) {
            if ($type === null && is_numeric($code)) {
                $codes[] = (int) $code;
            }
        }

        return $codes;
    }

    /**
     * Success status codes mapped to short model names, for status-based hydration of union returns.
     *
     * @return array<int, string>
     */
    public function typedSuccessCodes(): array
    {
        $codes = [];
        foreach ($this->successResponses as $code => $type) {
            if ($type !== null && is_numeric($code)) {
                $codes[(int) $code] = self::shortType($type);
            }
        }

        return $codes;
    }

    /**
     * Short model names mapped to the first success status code returning them.
     *
     * @return array<string, int>
     */
    public function successCodesByType(): array
    {
        $codes = [];
        foreach ($this->typedSuccessCodes() as $code => $type) {
            $codes[$type] ??= $code;
        }

        return $codes;
    }

    /**
     * Status code used by server controllers for a non-null (single-type) result.
     */
    public function primarySuccessCode(): int
    {
        foreach ($this->successResponses as $code => $type) {
            if ($type !== null && is_numeric($code)) {
                return (int) $code;
            }
        }

        foreach ($this->successCodes as $code) {
            if (is_numeric($code)) {
                return (int) $code;
            }
        }

        return 200;
    }

    /**
     * Whether the (single) return type is a generated model rather than a scalar/builtin type.
     */
    public function returnsModel(): bool
    {
        return str_contains($this->returnType, '\\');
    }

    /**
     * Short item/return type name without namespace and without "[]".
     */
    public function shortReturnItemType(): string
    {
        return self::shortType(str_ends_with($this->returnType, '[]') ? substr($this->returnType, 0, -2) : $this->returnType);
    }

    private static function shortType(string $type): string
    {
        $parts = explode('\\', $type);

        return (string) end($parts);
    }

    /**
     * All parameters in declaration order: path, query, header, cookie, then optional body.
     *
     * @return ParameterContext[]
     */
    public function allParams(): array
    {
        return array_merge($this->pathParams, $this->queryParams, $this->headerParams, $this->cookieParams);
    }
}
