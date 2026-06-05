<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Generator;

use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Config\PropertyNaming;

/**
 * Derives PHP names (classes, interfaces, enums, properties) from OpenAPI names.
 *
 * All public methods are pure — they do not mutate state.
 */
class NamingStrategy
{
    /** PHP reserved words that cannot be used as identifiers. */
    private const RESERVED_WORDS = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch',
        'class', 'clone', 'const', 'continue', 'declare', 'default', 'die', 'do',
        'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach',
        'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends',
        'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto',
        'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof',
        'interface', 'isset', 'list', 'match', 'namespace', 'new', 'or', 'print',
        'private', 'protected', 'public', 'readonly', 'require', 'require_once',
        'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use',
        'var', 'while', 'xor', 'yield',
    ];

    public function __construct(private readonly GeneratorConfig $config)
    {
    }

    // -------------------------------------------------------------------------
    // Class / enum / interface names
    // -------------------------------------------------------------------------

    /** Derive the PHP class name for an OAS schema named $schemaName. */
    public function className(string $schemaName): string
    {
        $name = $this->config->classPrefix . $this->toPascalCase($schemaName) . $this->config->classSuffix;

        return $this->escapeReservedWord($name);
    }

    /** Derive the PHP enum name for an OAS schema named $schemaName. */
    public function enumName(string $schemaName): string
    {
        $name = $this->config->classPrefix . $this->toPascalCase($schemaName) . $this->config->enumSuffix;

        return $this->escapeReservedWord($name);
    }

    /** Derive the PHP interface name for an OAS schema named $schemaName. */
    public function interfaceName(string $schemaName): string
    {
        $name = $this->config->classPrefix . $this->toPascalCase($schemaName) . $this->config->interfaceSuffix;

        return $this->escapeReservedWord($name);
    }

    /**
     * Derive the PHP class name for an inline (anonymous) object schema
     * that is a property of a parent class.
     *
     * e.g. parent="Pet", property="address" → "PetAddress"
     */
    public function inlineClassName(string $parentSchemaName, string $propertyName): string
    {
        return $this->className($parentSchemaName . ucfirst($this->toCamelCase($propertyName)));
    }

    // -------------------------------------------------------------------------
    // Property names
    // -------------------------------------------------------------------------

    /** Derive the PHP property name for an OAS property with wire name $wireName. */
    public function propertyName(string $wireName): string
    {
        $name = match ($this->config->propertyNaming) {
            PropertyNaming::CamelCase => $this->toCamelCase($wireName),
            PropertyNaming::SnakeCase => $this->toSnakeCase($wireName),
            PropertyNaming::Original => $wireName,
        };

        return $this->escapeReservedWord($name);
    }

    // -------------------------------------------------------------------------
    // Enum case names
    // -------------------------------------------------------------------------

    /**
     * Derive the PHP enum case name from a raw enum value string.
     * Enum case names use PascalCase and must start with a letter.
     */
    public function enumCaseName(string $value): string
    {
        // Prefix numeric-starting values with 'Value'
        $name = $this->toPascalCase($value);
        if ($name === '' || is_numeric($name[0])) {
            $name = 'Value' . $name;
        }

        return $this->escapeReservedWord($name);
    }

    // -------------------------------------------------------------------------
    // Namespace helpers
    // -------------------------------------------------------------------------

    public function modelNamespace(): string
    {
        return rtrim($this->config->modelNamespace, '\\');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function toPascalCase(string $input): string
    {
        // Split on non-alphanumeric characters and capitalise each word
        $words = preg_split('/[^a-zA-Z0-9]+/', $input) ?: [$input];

        return implode('', array_map('ucfirst', $words));
    }

    private function toCamelCase(string $input): string
    {
        $pascal = $this->toPascalCase($input);

        return lcfirst($pascal);
    }

    private function toSnakeCase(string $input): string
    {
        // Insert underscore before uppercase sequences, then lowercase
        $snake = preg_replace('/([a-z\d])([A-Z])/', '$1_$2', $input) ?? $input;
        $snake = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $snake) ?? $snake;
        $snake = strtolower($snake);
        // Replace non-word chars with underscores
        $snake = preg_replace('/[^a-z0-9_]/', '_', $snake) ?? $snake;

        return ltrim($snake, '_');
    }

    private function escapeReservedWord(string $name): string
    {
        if (in_array(strtolower($name), self::RESERVED_WORDS, true)) {
            return $name . $this->config->reservedWordSuffix;
        }

        return $name;
    }
}
