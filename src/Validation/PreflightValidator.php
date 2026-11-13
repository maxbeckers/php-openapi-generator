<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Validation;

use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;
use MaxBeckers\OpenApiGenerator\Config\FrameworkTarget;
use MaxBeckers\OpenApiGenerator\Config\ValidationStrategy;
use MaxBeckers\OpenApiGenerator\Generator\NamingStrategy;
use MaxBeckers\OpenApiGenerator\Spec\OpenApiSpec;
use MaxBeckers\OpenApiGenerator\Spec\Schema;

final class PreflightValidator
{
    public function validateConfig(GeneratorConfig $config, DiagnosticBag $diagnostics): void
    {
        foreach (get_object_vars($config) as $option => $value) {
            if (!(new \ReflectionProperty($config, $option))->isDefault()) {
                $diagnostics->add(new Diagnostic(
                    'config.unknown-option',
                    $config->strictSpecValidation ? 'error' : 'warning',
                    '/' . $option,
                    'Unknown dynamic configuration property.',
                    'Correct the option name; dynamic properties are ignored by the generator.',
                    '(configuration)',
                ));
            }
        }
        foreach (['includeTags', 'excludeTags', 'includeOperationIds', 'excludeOperationIds', 'includePaths', 'excludePaths', 'typeMapping'] as $option) {
            foreach ($config->$option as $key => $value) {
                if (!is_string($value)) {
                    $diagnostics->add(new Diagnostic(
                        'config.type',
                        'error',
                        Diagnostic::append('/' . $option, (string) $key),
                        'Expected a string configuration entry.',
                        'Use strings for filters and type mappings.',
                        '(configuration)',
                    ));
                }
            }
        }
        $checks = [
            'phpVersion' => preg_match('/^\d+\.\d+(?:\.\d+)?(?:[-+].*)?$/', $config->phpVersion) === 1
                && version_compare($config->phpVersion, '8.2', '>='),
            'outputDir' => trim($config->outputDir) !== '',
            'modelNamespace' => $this->validNamespace($config->modelNamespace),
            'apiNamespace' => $config->apiNamespace === '' || $this->validNamespace($config->apiNamespace),
        ];
        foreach ($checks as $option => $valid) {
            if (!$valid) {
                $diagnostics->add(new Diagnostic(
                    'config.invalid',
                    $config->strictSpecValidation ? 'error' : 'warning',
                    '/' . $option,
                    'Invalid generator configuration value.',
                    match ($option) {
                        'phpVersion' => 'Select PHP 8.2 or newer using a major.minor[.patch] version.',
                        'outputDir' => 'Choose a non-empty output directory.',
                        default => 'Use a valid PHP namespace.',
                    },
                    '(configuration)',
                ));
            }
        }
        $hasValidationFlags = $config->validateServerRequest || $config->validateServerResponse
            || $config->validateClientRequest || $config->validateClientResponse;
        if ($hasValidationFlags && $config->validationStrategy === ValidationStrategy::None) {
            $diagnostics->add(new Diagnostic(
                'config.validation-disabled',
                $config->strictSpecValidation ? 'error' : 'warning',
                '/validationStrategy',
                'Runtime validation flags have no effect with strategy None.',
                'Select a validation strategy or disable the flags.',
                '(configuration)',
            ));
        }
        if ($hasValidationFlags && $config->validationStrategy === ValidationStrategy::LaravelValidation
            && ($config->generationTarget !== GenerationTarget::Server || $config->frameworkTarget !== FrameworkTarget::Laravel)
        ) {
            $diagnostics->add(new Diagnostic(
                'config.validation-incompatible',
                $config->strictSpecValidation ? 'error' : 'warning',
                '/validationStrategy',
                'Laravel runtime validation is only wired into Laravel server controllers.',
                'Select a compatible strategy or Laravel server target.',
                '(configuration)',
            ));
        }
        if ($config->generationTarget === GenerationTarget::Client && (!$config->generateFromArray || !$config->generateToArray)) {
            $diagnostics->add(new Diagnostic(
                'config.client-mapping',
                $config->strictSpecValidation ? 'error' : 'warning',
                '/generateFromArray',
                'Client mapping requires DTO hydration and serialization helpers.',
                'Enable generateFromArray and generateToArray.',
                '(configuration)',
            ));
        }
    }

    /**
     * @param array<string, mixed> $raw
     * @param callable(string): array{?string, string} $source
     */
    public function validateSpec(OpenApiSpec $spec, array $raw, GeneratorConfig $config, DiagnosticBag $diagnostics, callable $source): void
    {
        $report = static function (string $code, string $pointer, string $message, string $hint, bool $blocking = false) use ($config, $diagnostics, $source): void {
            [$document, $pointer] = $source($pointer);
            $diagnostics->add(new Diagnostic($code, $blocking || $config->strictSpecValidation ? 'error' : 'warning', $pointer, $message, $hint, $document));
        };
        if (preg_match('/^3\.(0|1)\.\d+$/', $spec->openapi) !== 1) {
            $report('openapi.version', '/openapi', 'Only OpenAPI 3.0.x and 3.1.x are supported.', 'Convert the document to a supported OpenAPI version.');
        }
        foreach (['title', 'version'] as $field) {
            if (($raw['info'][$field] ?? '') === '') {
                $report('openapi.required', '/info/' . $field, 'Required OpenAPI metadata is missing.', 'Provide a non-empty ' . $field . '.');
            }
        }
        foreach (['webhooks', 'callbacks'] as $keyword) {
            if (!empty($raw[$keyword])) {
                $report('feature.unsupported', '/' . $keyword, 'This generator does not generate ' . $keyword . '.', 'Remove it from the generation input or implement it separately.');
            }
        }
        $naming = new NamingStrategy($config);
        $names = [];
        foreach ($spec->components->schemas as $name => $schema) {
            $pointer = Diagnostic::append('/components/schemas', (string) $name);
            $this->checkSchemaNames((string) $name, $schema, $pointer, $naming, $names, $report);
            if (isset($raw['components']['schemas'][$name]) && is_array($raw['components']['schemas'][$name])) {
                $this->checkFeatures($raw['components']['schemas'][$name], $pointer, $report);
            }
        }
        if ($config->generationTarget === GenerationTarget::Server && $config->apiNamespace === '' && $config->apiOutputDir === '') {
            return;
        }
        $groups = [];
        $methods = [];
        foreach ($spec->paths as $path => $item) {
            foreach ($item->getOperations() as $method => $operation) {
                $pointer = Diagnostic::append('/paths', $path) . '/' . $method;
                $operationRaw = $raw['paths'][$path][$method] ?? [];
                $tag = $operation->tags[0] ?? 'Default';
                $group = strtolower($naming->className($tag . 'Api'));
                if (isset($groups[$group]) && $groups[$group] !== $tag) {
                    $report('name.api-collision', $pointer, 'API tags normalize to the same PHP class: ' . $groups[$group] . ' and ' . $tag . '.', 'Rename the tags to distinct PHP identifiers.', true);
                }
                $groups[$group] = $tag;
                $name = $operation->operationId !== null
                    ? lcfirst($naming->className($operation->operationId))
                    : $method . $naming->className(str_replace(['/', '{', '}'], '_', $path));
                if ($config->generationTarget === GenerationTarget::Client && in_array(strtolower($name), [
                    '__construct', 'serializeparametervalue', 'buildquerystring', 'decodeerrorpayload',
                    'createapiexception', 'assertsuccessfulstatus', 'resolvesecurity', 'decodejsonresponse',
                    'validatenativerequestpayload', 'validatenativeresponsepayload',
                    'validatesymfonyrequestpayload', 'validatesymfonyresponsepayload',
                ], true)) {
                    $report('name.reserved-method', $pointer . '/operationId', 'PHP method "' . $name . '" is reserved by the generated client.', 'Choose a different operationId.', true);
                }
                $key = $group . '::' . strtolower($name);
                if (isset($methods[$key])) {
                    $report('name.operation-collision', $pointer . '/operationId', 'Operations at ' . $methods[$key] . ' and ' . $pointer . ' both normalize to PHP method "' . $name . '".', 'Choose a distinct operationId within the API group.', true);
                }
                $methods[$key] = $pointer;
                if ($operation->responses === []) {
                    $report('openapi.required', $pointer . '/responses', 'The operation declares no responses.', 'Declare at least one response.');
                }
                $params = [];
                $pathParams = [];
                foreach ($operation->parameters as $index => $parameter) {
                    $parameterPointer = $pointer . '/parameters/' . $index;
                    foreach ([
                        [$raw['paths'][$path]['parameters'] ?? [], Diagnostic::append('/paths', $path) . '/parameters'],
                        [$operationRaw['parameters'] ?? [], $pointer . '/parameters'],
                    ] as [$rawParameters, $base]) {
                        foreach ($rawParameters as $rawIndex => $rawParameter) {
                            $candidatePointer = $this->referencePointer($rawParameter, $base . '/' . $rawIndex);
                            $candidate = $this->nodeAt($raw, $candidatePointer);
                            if (($candidate['name'] ?? null) === $parameter->name && ($candidate['in'] ?? null) === $parameter->in) {
                                $parameterPointer = $candidatePointer;
                            }
                        }
                    }
                    $paramName = $naming->propertyName($parameter->name);
                    if (isset($params[$paramName])) {
                        $report('name.parameter-collision', $parameterPointer, 'Parameters normalize to the same PHP variable "' . $paramName . '".', 'Use distinct parameter names.', true);
                    }
                    $params[$paramName] = true;
                    if ($parameter->name === '' || !in_array($parameter->in, ['path', 'query', 'header', 'cookie'], true)) {
                        $report('parameter.invalid', $parameterPointer, 'A parameter requires a name and a supported location.', 'Set name and in to path, query, header, or cookie.');
                    }
                    if ($parameter->in === 'path') {
                        $pathParams[$parameter->name] = true;
                        if (!$parameter->required) {
                            $report('parameter.path-required', $parameterPointer . '/required', 'Path parameters must be required.', 'Set required to true.');
                        }
                    }
                }
                preg_match_all('/\{([^}]+)}/', $path, $placeholders);
                foreach ($placeholders[1] as $placeholder) {
                    if (!isset($pathParams[$placeholder])) {
                        $report('parameter.path-missing', $pointer . '/parameters', 'No path parameter declares "' . $placeholder . '".', 'Declare the parameter at path or operation level.');
                    }
                }
                foreach ($operation->requestBody?->content ?? [] as $type => $media) {
                    $bodyPointer = $this->referencePointer($operationRaw['requestBody'] ?? [], $pointer . '/requestBody');
                    if (strtolower(trim(explode(';', $type, 2)[0])) !== 'application/json') {
                        $report('representation.request', Diagnostic::append($bodyPointer . '/content', $type), 'Request content type "' . $type . '" is not supported; only application/json is supported.', 'Supply an application/json request contract.', true);
                    }
                }
                foreach ($operation->responses as $status => $response) {
                    $responsePointer = $this->referencePointer(
                        $operationRaw['responses'][$status] ?? [],
                        Diagnostic::append($pointer . '/responses', (string) $status),
                    );
                    if (preg_match('/^(?:[1-5][0-9]{2}|[1-5]XX|default)$/', (string) $status) !== 1) {
                        $report('response.status', Diagnostic::append($pointer . '/responses', (string) $status), 'Invalid OpenAPI response status.', 'Use an exact HTTP status, a status range, or default.');
                    }
                    if ((string) $status === '2XX') {
                        $report('feature.success-range', Diagnostic::append($pointer . '/responses', (string) $status), 'Success status ranges are not mapped faithfully by the current generator.', 'Declare exact success statuses.');
                    }
                    foreach ($response->content as $type => $media) {
                        if ($type !== 'application/json') {
                            $report('representation.response', Diagnostic::append($responsePointer . '/content', $type), 'The generated response mapper only selects application/json.', 'Use application/json or handle this representation outside the generated client.');
                        }
                    }
                    $this->checkFeatures($this->nodeAt($raw, $responsePointer), $responsePointer, $report, false);
                }
                if ($operation->requestBody !== null) {
                    $bodyPointer = $this->referencePointer($operationRaw['requestBody'] ?? [], $pointer . '/requestBody');
                    $this->checkFeatures($this->nodeAt($raw, $bodyPointer), $bodyPointer, $report, false);
                }
                if (is_array($operationRaw)) {
                    $this->checkFeatures($operationRaw, $pointer, $report, false);
                }
            }
        }
    }

    /**
     * @param array<string, string> $names
     * @param callable(string, string, string, string, bool=): void $report
     */
    private function checkSchemaNames(string $name, Schema $schema, string $pointer, NamingStrategy $naming, array &$names, callable $report): void
    {
        $generates = $schema->ref === null && (!empty($schema->enum) || $schema->type === 'object'
            || $schema->properties !== [] || $schema->allOf !== [] || $schema->oneOf !== [] || $schema->anyOf !== [] || $schema->discriminator !== null);
        if ($generates) {
            $phpName = !empty($schema->enum) ? $naming->enumName($name) : $naming->className($name);
            $key = strtolower($phpName);
            if (isset($names[$key])) {
                $report('name.schema-collision', $pointer, 'Schemas at ' . $names[$key] . ' and ' . $pointer . ' both normalize to PHP name "' . $phpName . '".', 'Rename the schema or change the naming configuration.', true);
            }
            $names[$key] = $pointer;
        }
        $cases = [];
        foreach ($schema->enum ?? [] as $index => $value) {
            if (!is_int($value) && !is_string($value)) {
                $report('representation.enum', $pointer . '/enum/' . $index, 'Generated backed enums require integer or string values.', 'Use a homogeneous integer or string enum.', true);
                continue;
            }
            if (gettype($value) !== gettype($schema->enum[0])) {
                $report('representation.enum', $pointer . '/enum/' . $index, 'Generated backed enums require one backing type.', 'Use a homogeneous integer or string enum.', true);
            }
            $case = $naming->enumCaseName((string) $value);
            if (isset($cases[$case])) {
                $report('name.enum-collision', $pointer . '/enum/' . $index, 'Enum values normalize to the same case "' . $case . '".', 'Use values with distinct PHP case names.', true);
            }
            $cases[$case] = true;
        }
        $properties = [];
        foreach ($schema->properties as $wire => $property) {
            $propertyPointer = Diagnostic::append($pointer . '/properties', $wire);
            $phpName = $naming->propertyName($wire);
            if (isset($properties[$phpName])) {
                $report('name.property-collision', $propertyPointer, 'Properties both normalize to PHP name "$' . $phpName . '" in schema "' . $name . '".', 'Rename the properties or change propertyNaming.', true);
            }
            $properties[$phpName] = true;
            if ($property->ref === null && ($property->type === 'object' || $property->properties !== [])) {
                $this->checkSchemaNames($naming->inlineSchemaName($name, $wire), $property, $propertyPointer, $naming, $names, $report);
            } elseif ($property->items !== null && $property->items->ref === null && ($property->items->type === 'object' || $property->items->properties !== [])) {
                $this->checkSchemaNames($naming->inlineSchemaName($name, $wire), $property->items, $propertyPointer . '/items', $naming, $names, $report);
            }
        }
    }

    /**
     * Walk schema-bearing fields only; examples, defaults and extensions are data.
     *
     * @param array<string, mixed> $node
     * @param callable(string, string, string, string, bool=): void $report
     */
    private function checkFeatures(array $node, string $pointer, callable $report, bool $schema = true): void
    {
        if ($schema) {
            foreach (['not', 'if', 'then', 'else', 'patternProperties', 'unevaluatedProperties', 'prefixItems', 'contains', 'dependentSchemas', 'dependentRequired'] as $keyword) {
                if (array_key_exists($keyword, $node)) {
                    $report('feature.unsupported', $pointer . '/' . $keyword, 'Schema keyword "' . $keyword . '" is not enforced by generated DTOs.', 'Enforce it outside generated DTOs or simplify the schema.');
                }
            }
            if (is_array($node['type'] ?? null) && count(array_diff($node['type'], ['null'])) > 1) {
                $report('feature.type-union', $pointer . '/type', 'Multiple non-null schema types are not represented faithfully.', 'Use supported compositions or one non-null type plus null.');
            }
        }
        foreach ($node as $field => $value) {
            if (!is_array($value)) {
                continue;
            }
            $childPointer = Diagnostic::append($pointer, (string) $field);
            if (in_array($field, ['schema', 'items', 'additionalProperties'], true)) {
                $this->checkFeatures($value, $childPointer, $report);
            } elseif (in_array($field, ['properties', 'allOf', 'oneOf', 'anyOf', 'content', 'responses', 'parameters', 'requestBody'], true)) {
                if ($field === 'requestBody') {
                    $this->checkFeatures($value, $childPointer, $report, false);
                    continue;
                }
                foreach ($value as $key => $child) {
                    if (is_array($child)) {
                        $this->checkFeatures($child, Diagnostic::append($childPointer, (string) $key), $report, in_array($field, ['properties', 'allOf', 'oneOf', 'anyOf'], true));
                    }
                }
            } elseif (!$schema && $field === 'callbacks') {
                $report('feature.unsupported', $childPointer, 'Callbacks are not generated.', 'Implement callbacks separately.');
            }
        }
    }

    private function validNamespace(string $namespace): bool
    {
        return preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(?:\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$/', $namespace) === 1;
    }

    /** @param array<string, mixed> $node */
    private function referencePointer(array $node, string $pointer): string
    {
        $ref = $node['$ref'] ?? null;

        return is_string($ref) && str_starts_with($ref, '#/') ? rawurldecode(substr($ref, 1)) : $pointer;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function nodeAt(array $raw, string $pointer): array
    {
        $node = $raw;
        foreach (explode('/', substr($pointer, 1)) as $token) {
            $key = str_replace(['~1', '~0'], ['/', '~'], $token);
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return [];
            }
            $node = $node[$key];
        }

        return is_array($node) ? $node : [];
    }
}
