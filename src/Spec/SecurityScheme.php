<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Spec;

class SecurityScheme
{
    public string $type = '';           // apiKey | http | oauth2 | openIdConnect
    public ?string $description = null;
    public ?string $name = null;        // for apiKey
    public ?string $in = null;          // for apiKey: header | query | cookie
    public ?string $scheme = null;      // for http: bearer | basic
    public ?string $bearerFormat = null;
    /** @var array<string, mixed> */
    public array $extensions = [];
}
