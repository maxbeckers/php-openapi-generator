<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Generator\Context;

use MaxBeckers\OpenApiGenerator\Spec\Schema;

/**
 * Represents a single resolved property ready for code generation.
 */
class PropertyContext
{
    /**
     * @param string      $wireName         Original name from the OpenAPI spec (used in fromArray/toArray)
     * @param string      $phpName          PHP identifier (camelCase / snake_case per config)
     * @param string      $phpType          PHP type string including nullability (e.g. "string", "?Pet", "Pet|null")
     * @param bool        $required         Whether the property is required in the schema
     * @param bool        $nullable         Whether the property can be null
     * @param bool        $readOnly         OAS readOnly flag
     * @param bool        $writeOnly        OAS writeOnly flag
     * @param mixed       $default          Default value (only meaningful when $hasDefault is true)
     * @param bool        $hasDefault       Whether a default value is present
     * @param bool        $isCircular       Whether this property is part of a circular reference
     * @param string|null $description      PHPDoc description
     * @param array<string, mixed> $extensions OAS x-* extensions
     */
    public function __construct(
        public readonly string $wireName,
        public readonly string $phpName,
        public readonly string $phpType,
        public readonly bool $required,
        public readonly bool $nullable,
        public readonly bool $readOnly,
        public readonly bool $writeOnly,
        public readonly mixed $default,
        public readonly bool $hasDefault,
        public readonly bool $isCircular,
        public readonly ?string $description,
        public readonly array $extensions,
        public readonly ?Schema $schema,
        /** @var string[] Extra PHP attributes injected by plugins (e.g. '#[\SensitiveParameter]') */
        public array $extraAttributes = [],
        /** Extra PHP code injected by plugins into the fromArray() body before this property's line */
        public ?string $extraCode = null,
    ) {
    }
}
