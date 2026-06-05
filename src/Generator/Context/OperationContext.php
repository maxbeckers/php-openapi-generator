<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Generator\Context;

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
     */
    public function __construct(
        public string $operationId,
        public string $httpMethod,
        public string $path,
        public string $phpPath,
        public array $pathParams,
        public array $queryParams,
        public array $headerParams,
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
    ) {
    }

    /**
     * All parameters in declaration order: path, query, header, then optional body.
     *
     * @return ParameterContext[]
     */
    public function allParams(): array
    {
        return array_merge($this->pathParams, $this->queryParams, $this->headerParams);
    }
}
