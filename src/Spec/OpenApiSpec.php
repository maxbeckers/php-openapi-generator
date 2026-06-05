<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Spec;

class OpenApiSpec
{
    public string $openapi = '';
    public Info $info;
    /** @var Server[] */
    public array $servers = [];
    /** @var array<string, PathItem>  key = path pattern, e.g. '/pets/{id}' */
    public array $paths = [];
    public Components $components;
    /** @var Tag[] */
    public array $tags = [];
    /** @var array<array<string, string[]>> */
    public array $security = [];
    /** @var array<string, mixed> */
    public array $extensions = [];

    public function __construct()
    {
        $this->info = new Info();
        $this->components = new Components();
    }
}
