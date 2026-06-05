<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Spec;

class Schema
{
    // Core type
    public ?string $type = null;
    public ?string $format = null;
    public ?string $ref = null;        // resolved from $ref
    public ?string $description = null;
    public bool $nullable = false;
    public bool $deprecated = false;
    public bool $readOnly = false;
    public bool $writeOnly = false;
    public mixed $default = null;
    public bool $hasDefault = false;
    public mixed $example = null;

    // Object
    /** @var array<string, Schema> */
    public array $properties = [];
    /** @var string[] */
    public array $required = [];
    public Schema|bool|null $additionalProperties = null;

    // Array
    public ?Schema $items = null;
    public ?int $minItems = null;
    public ?int $maxItems = null;
    public bool $uniqueItems = false;

    // String constraints
    public ?int $minLength = null;
    public ?int $maxLength = null;
    public ?string $pattern = null;

    // Numeric constraints
    public int|float|null $minimum = null;
    public int|float|null $maximum = null;
    public bool $exclusiveMinimum = false;
    public bool $exclusiveMaximum = false;
    public int|float|null $multipleOf = null;

    // Enum
    /** @var mixed[]|null */
    public ?array $enum = null;

    // Composition
    /** @var Schema[] */
    public array $allOf = [];
    /** @var Schema[] */
    public array $oneOf = [];
    /** @var Schema[] */
    public array $anyOf = [];
    public ?Schema $not = null;

    // Discriminator
    public ?Discriminator $discriminator = null;

    /** @var array<string, mixed> */
    public array $extensions = [];
}
