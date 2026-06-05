<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Spec;

class Tag
{
    public string $name = '';
    public ?string $description = null;
    /** @var array<string, mixed> */
    public array $extensions = [];
}
