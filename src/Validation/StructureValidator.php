<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Validation;

/**
 * Checks the shapes consumed by the typed loader, not the complete OpenAPI meta-schema.
 */
final class StructureValidator
{
    private const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * @param array<string, mixed> $data
     * @param callable(string): array{?string, string}|null $source
     */
    public function validate(array $data, DiagnosticBag $diagnostics, ?string $document = null, ?callable $source = null): void
    {
        $this->visit($data, 'document', '', $diagnostics, $document, $source);
    }

    /**
     * @param callable(string): array{?string, string}|null $source
     */
    private function visit(
        mixed $node,
        string $kind,
        string $pointer,
        DiagnosticBag $diagnostics,
        ?string $document,
        ?callable $source,
    ): void {
        if ($kind === 'schema' && is_bool($node)) {
            [$origin, $originalPointer] = $source !== null ? $source($pointer) : [$document, $pointer];
            $diagnostics->add(new Diagnostic(
                'feature.boolean-schema',
                'error',
                $originalPointer,
                'Boolean JSON Schemas are valid in OpenAPI 3.1 but are not supported by this loader.',
                'Use a supported object-form schema.',
                $origin,
            ));

            return;
        }
        if (!is_array($node) || ($node !== [] && array_is_list($node))) {
            $this->error($diagnostics, $pointer, 'Expected an object.', $document, $source);

            return;
        }

        if (array_key_exists('$ref', $node)) {
            if (!is_string($node['$ref']) || $node['$ref'] === '') {
                $this->error($diagnostics, Diagnostic::append($pointer, '$ref'), 'Expected a non-empty reference string.', $document, $source);
            }

            return;
        }

        $strings = ['description', 'summary'];
        $booleans = [];
        $objects = [];
        $maps = [];
        $lists = [];
        $stringLists = [];
        switch ($kind) {
            case 'document':
                $strings = ['openapi'];
                $objects = ['info' => 'info', 'components' => 'components'];
                $maps = ['paths' => 'path'];
                $lists = ['servers' => 'server', 'tags' => 'tag', 'security' => 'security'];
                break;
            case 'info':
                $strings = ['title', 'version', 'description'];
                break;
            case 'server':
                $strings = ['url', 'description'];
                break;
            case 'tag':
                $strings = ['name', 'description'];
                break;
            case 'components':
                $maps = ['schemas' => 'schema', 'parameters' => 'parameter', 'requestBodies' => 'body', 'responses' => 'response', 'securitySchemes' => 'scheme'];
                break;
            case 'path':
                $objects = array_fill_keys(self::METHODS, 'operation');
                $lists = ['parameters' => 'parameter'];
                break;
            case 'operation':
                $strings[] = 'operationId';
                $booleans = ['deprecated'];
                $objects = ['requestBody' => 'body'];
                $maps = ['responses' => 'response'];
                $lists = ['parameters' => 'parameter', 'security' => 'security'];
                $stringLists = ['tags'];
                break;
            case 'parameter':
                $strings = ['name', 'in', 'description', 'style'];
                $booleans = ['required', 'deprecated', 'explode'];
                $objects = ['schema' => 'schema'];
                break;
            case 'body':
                $booleans = ['required'];
                $maps = ['content' => 'media'];
                break;
            case 'response':
                $maps = ['content' => 'media'];
                break;
            case 'media':
                $objects = ['schema' => 'schema'];
                break;
            case 'scheme':
                $strings = ['type', 'description', 'name', 'in', 'scheme', 'bearerFormat'];
                break;
            case 'schema':
                $strings = ['description', 'format', 'pattern'];
                $booleans = ['nullable', 'deprecated', 'readOnly', 'writeOnly', 'uniqueItems'];
                $objects = ['items' => 'schema', 'not' => 'schema', 'discriminator' => 'discriminator'];
                $maps = ['properties' => 'schema'];
                $lists = ['allOf' => 'schema', 'oneOf' => 'schema', 'anyOf' => 'schema'];
                $stringLists = ['required'];
                if (array_key_exists('type', $node)) {
                    $types = is_array($node['type']) ? $node['type'] : [$node['type']];
                    if ($types === [] || !array_is_list($types)) {
                        $this->error($diagnostics, $pointer . '/type', 'Expected a type name or a list of type names.', $document, $source);
                    }
                    foreach ($types as $type) {
                        if (!is_string($type) || !in_array($type, ['string', 'integer', 'number', 'boolean', 'object', 'array', 'null'], true)) {
                            $this->error($diagnostics, $pointer . '/type', 'Expected a valid OpenAPI schema type.', $document, $source);
                            break;
                        }
                    }
                }
                foreach (['minimum', 'maximum', 'multipleOf', 'minLength', 'maxLength', 'minItems', 'maxItems', 'exclusiveMinimum', 'exclusiveMaximum'] as $field) {
                    if (!array_key_exists($field, $node)) {
                        continue;
                    }
                    $value = $node[$field];
                    $exclusive = str_starts_with($field, 'exclusive');
                    if (!is_int($value) && !is_float($value) && !($exclusive && is_bool($value))) {
                        $this->error($diagnostics, $pointer . '/' . $field, 'Expected a number' . ($exclusive ? ' or boolean.' : '.'), $document, $source);
                    }
                }
                if (array_key_exists('enum', $node) && (!is_array($node['enum']) || !array_is_list($node['enum']))) {
                    $this->error($diagnostics, $pointer . '/enum', 'Expected a list.', $document, $source);
                }
                if (array_key_exists('additionalProperties', $node) && !is_bool($node['additionalProperties'])) {
                    $objects['additionalProperties'] = 'schema';
                }
                break;
            case 'discriminator':
                $strings = ['propertyName'];
                if (isset($node['mapping'])) {
                    $this->stringMap($node['mapping'], false, $pointer . '/mapping', $diagnostics, $document, $source);
                }
                break;
            case 'security':
                $this->stringMap($node, true, $pointer, $diagnostics, $document, $source);
                break;
        }

        foreach ($strings as $field) {
            if (array_key_exists($field, $node) && !is_string($node[$field])) {
                $this->error($diagnostics, $pointer . '/' . $field, 'Expected a string.', $document, $source);
            }
        }
        foreach ($booleans as $field) {
            if (array_key_exists($field, $node) && !is_bool($node[$field])) {
                $this->error($diagnostics, $pointer . '/' . $field, 'Expected a boolean.', $document, $source);
            }
        }
        foreach ($objects as $field => $childKind) {
            if (array_key_exists($field, $node)) {
                $this->visit($node[$field], $childKind, $pointer . '/' . $field, $diagnostics, $document, $source);
            }
        }
        foreach ($maps as $field => $childKind) {
            if (!array_key_exists($field, $node)) {
                continue;
            }
            $map = $node[$field];
            if (!is_array($map) || ($map !== [] && array_is_list($map))) {
                $this->error($diagnostics, $pointer . '/' . $field, 'Expected an object map.', $document, $source);
                continue;
            }
            foreach ($map as $name => $child) {
                if (in_array($field, ['paths', 'responses'], true) && str_starts_with((string) $name, 'x-')) {
                    continue;
                }
                $this->visit($child, $childKind, Diagnostic::append($pointer . '/' . $field, (string) $name), $diagnostics, $document, $source);
            }
        }
        foreach ($lists + array_fill_keys($stringLists, 'string') as $field => $childKind) {
            if (!array_key_exists($field, $node)) {
                continue;
            }
            $list = $node[$field];
            if (!is_array($list) || !array_is_list($list)) {
                $this->error($diagnostics, $pointer . '/' . $field, 'Expected a list.', $document, $source);
                continue;
            }
            foreach ($list as $index => $child) {
                $childPointer = $pointer . '/' . $field . '/' . $index;
                if ($childKind === 'string') {
                    if (!is_string($child)) {
                        $this->error($diagnostics, $childPointer, 'Expected a string.', $document, $source);
                    }
                } else {
                    $this->visit($child, $childKind, $childPointer, $diagnostics, $document, $source);
                }
            }
        }
    }

    /** @param callable(string): array{?string, string}|null $source */
    private function stringMap(mixed $node, bool $lists, string $pointer, DiagnosticBag $diagnostics, ?string $document, ?callable $source): void
    {
        if (!is_array($node) || ($node !== [] && array_is_list($node))) {
            $this->error($diagnostics, $pointer, 'Expected an object map.', $document, $source);

            return;
        }
        foreach ($node as $key => $value) {
            if ($lists ? (!is_array($value) || !array_is_list($value) || count(array_filter($value, 'is_string')) !== count($value)) : !is_string($value)) {
                $this->error($diagnostics, Diagnostic::append($pointer, (string) $key), $lists ? 'Expected a list of scope strings.' : 'Expected a reference string.', $document, $source);
            }
        }
    }

    /** @param callable(string): array{?string, string}|null $source */
    private function error(DiagnosticBag $diagnostics, string $pointer, string $message, ?string $document, ?callable $source): void
    {
        [$document, $pointer] = $source !== null ? $source($pointer) : [$document, $pointer];
        $diagnostics->add(new Diagnostic('structure.type', 'error', $pointer, $message, 'Correct the field type in the specification.', $document));
    }
}
