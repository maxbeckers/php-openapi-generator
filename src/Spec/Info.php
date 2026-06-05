<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Spec;

class Info
{
    public string $title = '';
    public string $version = '';
    public ?string $description = null;
    /** @var array<string, mixed> */
    public array $extensions = [];
}
