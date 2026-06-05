<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Spec;

class MediaType
{
    public ?Schema $schema = null;
    /** @var array<string, mixed> */
    public array $extensions = [];
}
