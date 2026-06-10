<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Loader;

use MaxBeckers\OpenApiGenerator\Spec\Components;
use MaxBeckers\OpenApiGenerator\Spec\Discriminator;
use MaxBeckers\OpenApiGenerator\Spec\Info;
use MaxBeckers\OpenApiGenerator\Spec\MediaType;
use MaxBeckers\OpenApiGenerator\Spec\OpenApiSpec;
use MaxBeckers\OpenApiGenerator\Spec\Operation;
use MaxBeckers\OpenApiGenerator\Spec\Parameter;
use MaxBeckers\OpenApiGenerator\Spec\PathItem;
use MaxBeckers\OpenApiGenerator\Spec\RequestBody;
use MaxBeckers\OpenApiGenerator\Spec\Response;
use MaxBeckers\OpenApiGenerator\Spec\Schema;
use MaxBeckers\OpenApiGenerator\Spec\SecurityScheme;
use MaxBeckers\OpenApiGenerator\Spec\Server;
use MaxBeckers\OpenApiGenerator\Spec\Tag;
use MaxBeckers\YamlParser\YamlParser;

class OpenApiLoader
{
    /**
     * Load an OpenAPI spec from a file path (YAML or JSON).
     */
    public function loadFile(string $path): OpenApiSpec
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException(sprintf('OpenAPI spec file not found: %s', $path));
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'json') {
            $raw = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } else {
            $parser = new YamlParser();
            $raw = $parser->parse(file_get_contents($path));
            $raw = $this->normalise($raw);

            if (is_array($raw) && !isset($raw['openapi']) && isset($raw[0]) && is_array($raw[0])) {
                $raw = $raw[0];
            }
        }

        return $this->load($raw);
    }

    /**
     * Load an OpenAPI spec from a pre-parsed array.
     *
     * @param array<string, mixed> $data
     */
    public function load(array $data): OpenApiSpec
    {
        $spec = new OpenApiSpec();
        $spec->openapi = (string) ($data['openapi'] ?? '');
        $spec->info = $this->loadInfo($data['info'] ?? []);
        $spec->servers = $this->loadServers($data['servers'] ?? []);
        $spec->tags = $this->loadTags($data['tags'] ?? []);
        $spec->security = $data['security'] ?? [];
        $spec->extensions = $this->extractExtensions($data);

        $spec->components = $this->loadComponents($data['components'] ?? []);

        foreach ($data['paths'] ?? [] as $path => $pathData) {
            if (!is_array($pathData)) {
                continue;
            }
            $spec->paths[(string) $path] = $this->loadPathItem($pathData, $spec->components);
        }

        return $spec;
    }

    /** @param array<string, mixed> $data */
    private function loadInfo(array $data): Info
    {
        $info = new Info();
        $info->title = (string) ($data['title'] ?? '');
        $info->version = (string) ($data['version'] ?? '');
        $info->description = isset($data['description']) ? (string) $data['description'] : null;
        $info->extensions = $this->extractExtensions($data);

        return $info;
    }

    /**
     * @param array<int, mixed> $data
     *
     * @return Server[]
     */
    private function loadServers(array $data): array
    {
        $servers = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $server = new Server();
            $server->url = (string) ($item['url'] ?? '');
            $server->description = isset($item['description']) ? (string) $item['description'] : null;
            $server->extensions = $this->extractExtensions($item);
            $servers[] = $server;
        }

        return $servers;
    }

    /**
     * @param array<int, mixed> $data
     *
     * @return Tag[]
     */
    private function loadTags(array $data): array
    {
        $tags = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $tag = new Tag();
            $tag->name = (string) ($item['name'] ?? '');
            $tag->description = isset($item['description']) ? (string) $item['description'] : null;
            $tag->extensions = $this->extractExtensions($item);
            $tags[] = $tag;
        }

        return $tags;
    }

    /** @param array<string, mixed> $data */
    private function loadComponents(array $data): Components
    {
        $components = new Components();
        $components->extensions = $this->extractExtensions($data);

        foreach ($data['schemas'] ?? [] as $name => $schemaData) {
            if (!is_array($schemaData)) {
                continue;
            }
            $components->schemas[(string) $name] = $this->loadSchema($schemaData);
        }

        foreach ($data['requestBodies'] ?? [] as $name => $rbData) {
            if (!is_array($rbData)) {
                continue;
            }
            $components->requestBodies[(string) $name] = $this->loadRequestBody($rbData);
        }

        foreach ($data['responses'] ?? [] as $name => $respData) {
            if (!is_array($respData)) {
                continue;
            }
            $components->responses[(string) $name] = $this->loadResponse($respData);
        }

        foreach ($data['parameters'] ?? [] as $name => $paramData) {
            if (!is_array($paramData)) {
                continue;
            }
            $components->parameters[(string) $name] = $this->loadParameter($paramData);
        }

        foreach ($data['securitySchemes'] ?? [] as $name => $ssData) {
            if (!is_array($ssData)) {
                continue;
            }
            $components->securitySchemes[(string) $name] = $this->loadSecurityScheme($ssData);
        }

        return $components;
    }

    /** @param array<string, mixed> $data */
    public function loadSchema(array $data): Schema
    {
        $schema = new Schema();

        if (isset($data['$ref'])) {
            $schema->ref = (string) $data['$ref'];

            return $schema;
        }

        $schema->description = isset($data['description']) ? (string) $data['description'] : null;
        $schema->deprecated = (bool) ($data['deprecated'] ?? false);
        $schema->readOnly = (bool) ($data['readOnly'] ?? false);
        $schema->writeOnly = (bool) ($data['writeOnly'] ?? false);
        $schema->format = isset($data['format']) ? (string) $data['format'] : null;

        $rawType = $data['type'] ?? null;
        if (is_array($rawType)) {
            $nonNull = array_values(array_filter($rawType, fn ($t) => $t !== 'null'));
            $schema->type = count($nonNull) === 1 ? $nonNull[0] : null;
            $schema->nullable = in_array('null', $rawType, true);
        } else {
            $schema->type = $rawType !== null ? (string) $rawType : null;
            $schema->nullable = (bool) ($data['nullable'] ?? false);
        }

        if (array_key_exists('default', $data)) {
            $schema->default = $data['default'];
            $schema->hasDefault = true;
        }

        $schema->example = $data['example'] ?? null;

        if (isset($data['enum']) && is_array($data['enum'])) {
            $schema->enum = $data['enum'];
        }

        $schema->minLength = isset($data['minLength']) ? (int) $data['minLength'] : null;
        $schema->maxLength = isset($data['maxLength']) ? (int) $data['maxLength'] : null;
        $schema->pattern = isset($data['pattern']) ? (string) $data['pattern'] : null;

        $schema->minimum = isset($data['minimum']) ? $data['minimum'] + 0 : null;
        $schema->maximum = isset($data['maximum']) ? $data['maximum'] + 0 : null;
        $schema->multipleOf = isset($data['multipleOf']) ? $data['multipleOf'] + 0 : null;

        if (isset($data['exclusiveMinimum'])) {
            if (is_bool($data['exclusiveMinimum'])) {
                $schema->exclusiveMinimum = $data['exclusiveMinimum'];
            } else {
                $schema->exclusiveMinimum = true;
                $schema->minimum = $data['exclusiveMinimum'] + 0;
            }
        }
        if (isset($data['exclusiveMaximum'])) {
            if (is_bool($data['exclusiveMaximum'])) {
                $schema->exclusiveMaximum = $data['exclusiveMaximum'];
            } else {
                $schema->exclusiveMaximum = true;
                $schema->maximum = $data['exclusiveMaximum'] + 0;
            }
        }

        if (isset($data['properties']) && is_array($data['properties'])) {
            foreach ($data['properties'] as $propName => $propData) {
                if (is_array($propData)) {
                    $schema->properties[(string) $propName] = $this->loadSchema($propData);
                }
            }
        }

        if (isset($data['required']) && is_array($data['required'])) {
            $schema->required = array_map('strval', $data['required']);
        }

        if (isset($data['additionalProperties'])) {
            if (is_bool($data['additionalProperties'])) {
                $schema->additionalProperties = $data['additionalProperties'];
            } elseif (is_array($data['additionalProperties'])) {
                $schema->additionalProperties = $this->loadSchema($data['additionalProperties']);
            }
        }

        if (isset($data['items']) && is_array($data['items'])) {
            $schema->items = $this->loadSchema($data['items']);
        }
        $schema->minItems = isset($data['minItems']) ? (int) $data['minItems'] : null;
        $schema->maxItems = isset($data['maxItems']) ? (int) $data['maxItems'] : null;
        $schema->uniqueItems = (bool) ($data['uniqueItems'] ?? false);

        foreach ($data['allOf'] ?? [] as $sub) {
            if (is_array($sub)) {
                $schema->allOf[] = $this->loadSchema($sub);
            }
        }
        foreach ($data['oneOf'] ?? [] as $sub) {
            if (is_array($sub)) {
                $schema->oneOf[] = $this->loadSchema($sub);
            }
        }
        foreach ($data['anyOf'] ?? [] as $sub) {
            if (is_array($sub)) {
                $schema->anyOf[] = $this->loadSchema($sub);
            }
        }
        if (isset($data['not']) && is_array($data['not'])) {
            $schema->not = $this->loadSchema($data['not']);
        }

        if (isset($data['discriminator']) && is_array($data['discriminator'])) {
            $schema->discriminator = $this->loadDiscriminator($data['discriminator']);
        }

        $schema->extensions = $this->extractExtensions($data);

        return $schema;
    }

    /** @param array<string, mixed> $data */
    private function loadDiscriminator(array $data): Discriminator
    {
        $d = new Discriminator();
        $d->propertyName = (string) ($data['propertyName'] ?? '');
        $d->mapping = [];
        if (isset($data['mapping']) && is_array($data['mapping'])) {
            foreach ($data['mapping'] as $value => $ref) {
                $d->mapping[(string) $value] = (string) $ref;
            }
        }

        return $d;
    }

    /** @param array<string, mixed> $data */
    private function loadPathItem(array $data, Components $components): PathItem
    {
        $item = new PathItem();
        $item->summary = isset($data['summary']) ? (string) $data['summary'] : null;
        $item->description = isset($data['description']) ? (string) $data['description'] : null;
        $item->extensions = $this->extractExtensions($data);

        foreach ($data['parameters'] ?? [] as $paramData) {
            if (is_array($paramData)) {
                $item->parameters[] = $this->loadParameterOrRef($paramData, $components);
            }
        }

        foreach (['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'] as $method) {
            if (isset($data[$method]) && is_array($data[$method])) {
                $op = $this->loadOperation($data[$method], $components);
                $op->parameters = $this->mergeParameters($item->parameters, $op->parameters);
                $item->$method = $op;
            }
        }

        return $item;
    }

    /**
     * Merge path-level parameters with operation-level parameters.
     * Operation-level parameters with the same name+in take precedence.
     *
     * @param Parameter[] $pathParams
     * @param Parameter[] $opParams
     *
     * @return Parameter[]
     */
    private function mergeParameters(array $pathParams, array $opParams): array
    {
        $merged = [];

        $opIndex = [];
        foreach ($opParams as $p) {
            $opIndex[$p->name . '|' . $p->in] = true;
        }

        foreach ($pathParams as $p) {
            if (!isset($opIndex[$p->name . '|' . $p->in])) {
                $merged[] = $p;
            }
        }

        foreach ($opParams as $p) {
            $merged[] = $p;
        }

        return $merged;
    }

    /** @param array<string, mixed> $data */
    private function loadOperation(array $data, Components $components): Operation
    {
        $op = new Operation();
        $op->operationId = isset($data['operationId']) ? (string) $data['operationId'] : null;
        $op->summary = isset($data['summary']) ? (string) $data['summary'] : null;
        $op->description = isset($data['description']) ? (string) $data['description'] : null;
        $op->deprecated = (bool) ($data['deprecated'] ?? false);
        $op->tags = isset($data['tags']) && is_array($data['tags'])
            ? array_map('strval', $data['tags'])
            : [];
        $op->security = $data['security'] ?? [];
        $op->extensions = $this->extractExtensions($data);

        foreach ($data['parameters'] ?? [] as $paramData) {
            if (is_array($paramData)) {
                $op->parameters[] = $this->loadParameterOrRef($paramData, $components);
            }
        }

        if (isset($data['requestBody']) && is_array($data['requestBody'])) {
            $op->requestBody = $this->loadRequestBodyOrRef($data['requestBody'], $components);
        }

        foreach ($data['responses'] ?? [] as $statusCode => $respData) {
            if (is_array($respData)) {
                $op->responses[(string) $statusCode] = $this->loadResponseOrRef($respData, $components);
            }
        }

        return $op;
    }

    /** @param array<string, mixed> $data */
    private function loadParameterOrRef(array $data, Components $components): Parameter
    {
        if (isset($data['$ref'])) {
            $name = $this->extractRefName($data['$ref']);
            if (isset($components->parameters[$name])) {
                return $components->parameters[$name];
            }
        }

        return $this->loadParameter($data);
    }

    /** @param array<string, mixed> $data */
    private function loadParameter(array $data): Parameter
    {
        $param = new Parameter();
        $param->name = (string) ($data['name'] ?? '');
        $param->in = (string) ($data['in'] ?? '');
        $param->required = (bool) ($data['required'] ?? ($param->in === 'path'));
        $param->description = isset($data['description']) ? (string) $data['description'] : null;
        $param->deprecated = (bool) ($data['deprecated'] ?? false);
        $param->extensions = $this->extractExtensions($data);

        if (isset($data['schema']) && is_array($data['schema'])) {
            $param->schema = $this->loadSchema($data['schema']);
        }

        return $param;
    }

    /** @param array<string, mixed> $data */
    private function loadRequestBodyOrRef(array $data, Components $components): RequestBody
    {
        if (isset($data['$ref'])) {
            $name = $this->extractRefName($data['$ref']);
            if (isset($components->requestBodies[$name])) {
                return $components->requestBodies[$name];
            }
        }

        return $this->loadRequestBody($data);
    }

    /** @param array<string, mixed> $data */
    private function loadRequestBody(array $data): RequestBody
    {
        $rb = new RequestBody();
        $rb->description = isset($data['description']) ? (string) $data['description'] : null;
        $rb->required = (bool) ($data['required'] ?? false);
        $rb->extensions = $this->extractExtensions($data);

        foreach ($data['content'] ?? [] as $contentType => $mediaData) {
            if (is_array($mediaData)) {
                $rb->content[(string) $contentType] = $this->loadMediaType($mediaData);
            }
        }

        return $rb;
    }

    /** @param array<string, mixed> $data */
    private function loadResponseOrRef(array $data, Components $components): Response
    {
        if (isset($data['$ref'])) {
            $name = $this->extractRefName($data['$ref']);
            if (isset($components->responses[$name])) {
                return $components->responses[$name];
            }
        }

        return $this->loadResponse($data);
    }

    /** @param array<string, mixed> $data */
    private function loadResponse(array $data): Response
    {
        $resp = new Response();
        $resp->description = (string) ($data['description'] ?? '');
        $resp->extensions = $this->extractExtensions($data);

        foreach ($data['content'] ?? [] as $contentType => $mediaData) {
            if (is_array($mediaData)) {
                $resp->content[(string) $contentType] = $this->loadMediaType($mediaData);
            }
        }

        return $resp;
    }

    /** @param array<string, mixed> $data */
    private function loadMediaType(array $data): MediaType
    {
        $mt = new MediaType();
        $mt->extensions = $this->extractExtensions($data);

        if (isset($data['schema']) && is_array($data['schema'])) {
            $mt->schema = $this->loadSchema($data['schema']);
        }

        return $mt;
    }

    /** @param array<string, mixed> $data */
    private function loadSecurityScheme(array $data): SecurityScheme
    {
        $ss = new SecurityScheme();
        $ss->type = (string) ($data['type'] ?? '');
        $ss->description = isset($data['description']) ? (string) $data['description'] : null;
        $ss->name = isset($data['name']) ? (string) $data['name'] : null;
        $ss->in = isset($data['in']) ? (string) $data['in'] : null;
        $ss->scheme = isset($data['scheme']) ? (string) $data['scheme'] : null;
        $ss->bearerFormat = isset($data['bearerFormat']) ? (string) $data['bearerFormat'] : null;
        $ss->extensions = $this->extractExtensions($data);

        return $ss;
    }

    private function extractRefName(string $ref): string
    {
        return (string) (array_reverse(explode('/', $ref))[0] ?? '');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function extractExtensions(array $data): array
    {
        return array_filter($data, function ($key) {
            return str_starts_with($key, 'x-');
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Recursively normalise ArrayObject / Traversable to plain PHP arrays.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private function normalise(mixed $value): mixed
    {
        if ($value instanceof \Traversable) {
            $value = iterator_to_array($value);
        }

        if (is_array($value)) {
            return array_map(function ($v) {
                return $this->normalise($v);
            }, $value);
        }

        return $value;
    }

}
