<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Spec;

class Components
{
    /** @var array<string, Schema> */
    public array $schemas = [];
    /** @var array<string, RequestBody> */
    public array $requestBodies = [];
    /** @var array<string, Response> */
    public array $responses = [];
    /** @var array<string, Parameter> */
    public array $parameters = [];
    /** @var array<string, SecurityScheme> */
    public array $securitySchemes = [];
    /** @var array<string, mixed> */
    public array $extensions = [];
}
