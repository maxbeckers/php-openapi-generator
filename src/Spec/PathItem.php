<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Spec;

class PathItem
{
    public ?string $summary = null;
    public ?string $description = null;
    /** @var Parameter[] path-level parameters, merged into each Operation */
    public array $parameters = [];
    public ?Operation $get = null;
    public ?Operation $put = null;
    public ?Operation $post = null;
    public ?Operation $delete = null;
    public ?Operation $options = null;
    public ?Operation $head = null;
    public ?Operation $patch = null;
    public ?Operation $trace = null;
    /** @var array<string, mixed> */
    public array $extensions = [];

    /**
     * Returns all non-null operations keyed by HTTP method.
     *
     * @return array<string, Operation>
     */
    public function getOperations(): array
    {
        $ops = [];
        foreach (['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'] as $method) {
            if ($this->$method !== null) {
                $ops[$method] = $this->$method;
            }
        }

        return $ops;
    }
}
