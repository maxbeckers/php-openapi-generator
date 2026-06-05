<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Spec;

class Parameter
{
    public string $name = '';
    public string $in = '';   // path | query | header | cookie
    public bool $required = false;
    public ?string $description = null;
    public bool $deprecated = false;
    public ?Schema $schema = null;
    /** @var array<string, mixed> */
    public array $extensions = [];
}
