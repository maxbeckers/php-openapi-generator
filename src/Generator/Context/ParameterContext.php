<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Generator\Context;

/**
 * A single resolved parameter (path/query/header/cookie) ready for template rendering.
 */
readonly class ParameterContext
{
    /**
     * @param string      $name      PHP parameter name (camelCase)
     * @param string      $wireName  Original OAS parameter name
     * @param string      $phpType   PHP type string; generated classes are fully qualified, builtin classes start with "\"
     * @param bool        $required
     * @param string      $in        'path' | 'query' | 'header' | 'cookie'
     * @param string|null $description
     * @param string      $style     OAS serialization style (form, simple, spaceDelimited, ...)
     * @param bool        $explode   OAS explode flag
     * @param string|null $docType   PHPDoc type for array parameters, e.g. list<string>
     * @param string|null $format    'date' or 'date-time' for date parameters
     * @param string      $valueType Scalar value type used for (de)serialization: string, int, float, bool, date,
     *                               date-time, or an enum FQCN; for arrays this is the item type
     * @param bool        $deprecated
     */
    public function __construct(
        public string $name,
        public string $wireName,
        public string $phpType,
        public bool $required,
        public string $in,
        public ?string $description,
        public string $style = 'form',
        public bool $explode = true,
        public ?string $docType = null,
        public ?string $format = null,
        public string $valueType = 'string',
        public bool $deprecated = false,
    ) {
    }

    /**
     * PHP type for method signatures, using the short class name for generated types.
     */
    public function signatureType(): string
    {
        $nullable = str_starts_with($this->phpType, '?');
        $type = ltrim($this->phpType, '?');
        if (str_starts_with($type, '\\')) {
            return ($nullable ? '?' : '') . $type;
        }

        $parts = explode('\\', $type);

        return ($nullable ? '?' : '') . end($parts);
    }

    public function isArray(): bool
    {
        return ltrim($this->phpType, '?') === 'array';
    }

    /**
     * Fully-qualified class name that must be imported, or null for builtin types.
     */
    public function importType(): ?string
    {
        $type = ltrim($this->phpType, '?');
        if (str_contains($type, '\\') && !str_starts_with($type, '\\')) {
            return $type;
        }

        if (str_contains($this->valueType, '\\')) {
            return $this->valueType;
        }

        return null;
    }

    /**
     * PHP expression that yields the wire-ready value of this parameter (formats dates; other values unchanged).
     */
    public function wireValueExpression(?bool $nullsafe = null): string
    {
        $variable = '$' . $this->name;
        $pattern = match ($this->format) {
            'date' => "'Y-m-d'",
            'date-time' => '\DateTimeInterface::RFC3339',
            default => null,
        };

        if ($pattern === null || $this->isArray()) {
            return $variable;
        }

        return $variable . (($nullsafe ?? !$this->required) ? '?->' : '->') . 'format(' . $pattern . ')';
    }

    /**
     * Delimiter used between array items on the wire for non-exploded parameters.
     */
    public function arrayDelimiter(): string
    {
        return match ($this->style) {
            'spaceDelimited' => ' ',
            'pipeDelimited' => '|',
            default => ',',
        };
    }

    /**
     * Whether an array query parameter is sent as repeated name=value pairs.
     */
    public function isMultiValueQuery(): bool
    {
        return $this->in === 'query' && $this->isArray() && ($this->style === 'form' || $this->style === 'deepObject') && $this->explode;
    }

    /**
     * Short class name of an enum value type, or null for scalar value types.
     */
    public function shortValueType(): ?string
    {
        if (!str_contains($this->valueType, '\\')) {
            return null;
        }

        $parts = explode('\\', $this->valueType);

        return end($parts);
    }
}
