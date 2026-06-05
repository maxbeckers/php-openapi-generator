<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Spec;

class RequestBody
{
    public ?string $description = null;
    public bool $required = false;
    /** @var array<string, MediaType>  key = content-type, e.g. 'application/json' */
    public array $content = [];
    /** @var array<string, mixed> */
    public array $extensions = [];
}
