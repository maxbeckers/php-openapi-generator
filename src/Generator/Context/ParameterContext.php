<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Generator\Context;

/**
 * A single resolved parameter (path/query/header) ready for template rendering.
 */
readonly class ParameterContext
{
    /**
     * @param string      $name      PHP parameter name (camelCase)
     * @param string      $wireName  Original OAS parameter name
     * @param string      $phpType   PHP type string
     * @param bool        $required
     * @param string      $in        'path' | 'query' | 'header' | 'cookie'
     * @param string|null $description
     */
    public function __construct(
        public string $name,
        public string $wireName,
        public string $phpType,
        public bool $required,
        public string $in,
        public ?string $description,
    ) {
    }
}
