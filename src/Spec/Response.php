<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Spec;

class Response
{
    public string $description = '';
    /** @var array<string, MediaType>  key = content-type */
    public array $content = [];
    /** @var array<string, mixed> */
    public array $extensions = [];
}
