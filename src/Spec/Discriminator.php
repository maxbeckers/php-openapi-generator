<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Spec;

class Discriminator
{
    public string $propertyName = '';
    /** @var array<string, string> */
    public array $mapping = [];
}
