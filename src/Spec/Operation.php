<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Spec;

class Operation
{
    public ?string $operationId = null;
    public ?string $summary = null;
    public ?string $description = null;
    public bool $deprecated = false;
    /** @var string[] */
    public array $tags = [];
    /** @var Parameter[] */
    public array $parameters = [];
    public ?RequestBody $requestBody = null;
    /** @var array<string, Response>  key = HTTP status code string, e.g. '200', 'default' */
    public array $responses = [];
    /** @var array<array<string, string[]>> */
    public array $security = [];
    /** @var array<string, mixed> */
    public array $extensions = [];
}
