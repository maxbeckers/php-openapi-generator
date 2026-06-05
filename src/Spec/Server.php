<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Spec;

class Server
{
    public string $url = '';
    public ?string $description = null;
    /** @var array<string, mixed> */
    public array $extensions = [];
}
