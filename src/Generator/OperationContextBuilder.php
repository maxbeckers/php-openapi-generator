<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Generator;

use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;
use MaxBeckers\OpenApiGenerator\Generator\Context\ApiGroupContext;
use MaxBeckers\OpenApiGenerator\Generator\Context\OperationContext;
use MaxBeckers\OpenApiGenerator\Generator\Context\ParameterContext;
use MaxBeckers\OpenApiGenerator\Generator\Context\SecuritySchemeContext;
use MaxBeckers\OpenApiGenerator\Spec\Components;
use MaxBeckers\OpenApiGenerator\Spec\MediaType;
use MaxBeckers\OpenApiGenerator\Spec\OpenApiSpec;
use MaxBeckers\OpenApiGenerator\Spec\Operation;
use MaxBeckers\OpenApiGenerator\Spec\Parameter;
use MaxBeckers\OpenApiGenerator\Spec\Schema;

/**
 * Builds ApiGroupContext objects from an OpenApiSpec.
 *
 * Groups operations by the first tag; untagged operations go into 'Default'.
 */
readonly class OperationContextBuilder
{
    public function __construct(
        private GeneratorConfig $config,
        private NamingStrategy $naming,
        private SchemaResolver $resolver,
    ) {
    }

    /**
     * @return ApiGroupContext[]
     */
    public function build(OpenApiSpec $spec): array
    {
        $groups = [];   // tag → ['operations' => OperationContext[]]
        $isClient = $this->config->generationTarget === GenerationTarget::Client;
        $securitySchemes = $isClient && $this->config->generateSecuritySchemes
            ? $this->buildSecuritySchemes($spec->components)
            : [];

        foreach ($spec->paths as $path => $pathItem) {
            foreach ($pathItem->getOperations() as $httpMethod => $operation) {
                $tag = $operation->tags[0] ?? 'Default';
                $opCtx = $this->buildOperationContext(
                    $path,
                    $httpMethod,
                    $operation,
                    $spec->components,
                    $securitySchemes === [] ? null : $this->resolveSecurity($operation, $spec->security, $securitySchemes),
                );
                foreach ($groups[$tag] ?? [] as $existingOperation) {
                    if ($existingOperation->operationId === $opCtx->operationId) {
                        throw new \InvalidArgumentException(sprintf(
                            'Operations "%s" and "%s" in tag "%s" both normalize to PHP method "%s".',
                            $existingOperation->operationId,
                            $opCtx->operationId,
                            $tag,
                            $opCtx->operationId,
                        ));
                    }
                }
                $groups[$tag][] = $opCtx;
            }
        }

        $apiNamespace = $this->resolveApiNamespace();
        $result = [];

        foreach ($groups as $tag => $operations) {
            $className = $this->naming->className($tag . 'Api');
            $imports = new ImportManager($apiNamespace);
            $interfaceImports = new ImportManager($apiNamespace);
            $groupSchemes = [];

            // Register all return/param types with the import manager
            foreach ($operations as $op) {
                foreach ($op->security ?? [] as $alternative) {
                    foreach ($alternative as $schemeName) {
                        $groupSchemes[$schemeName] = $securitySchemes[$schemeName];
                    }
                }
                if ($isClient && $this->config->typedErrorResponses) {
                    $imports->add($apiNamespace . '\\Exception\\ApiException');
                    foreach ($op->errorExceptionClasses() as $exceptionClass) {
                        $imports->add($apiNamespace . '\\Exception\\' . $exceptionClass);
                    }
                    foreach ($op->errorResponses as $errorType) {
                        if ($errorType !== null) {
                            $imports->add($errorType);
                        }
                    }
                }
                if ($op->requestBodyType !== null && str_contains($op->requestBodyType, '\\')) {
                    $imports->add($op->requestBodyType);
                    $interfaceImports->add($op->requestBodyType);
                }
                if ($op->returnType !== 'void' && $op->returnType !== 'mixed') {
                    foreach ($op->returnTypes as $returnType) {
                        if (!str_contains($returnType, '\\')) {
                            continue;
                        }
                        $isArray = str_ends_with($returnType, '[]');
                        $type = $isArray ? substr($returnType, 0, -2) : $returnType;
                        if (!$isArray || $this->config->generationTarget === GenerationTarget::Client) {
                            $imports->add($type);
                        }
                        if (!$isArray) {
                            $interfaceImports->add($type);
                        }
                    }
                }
                foreach ($op->allParams() as $param) {
                    $importType = $param->importType();
                    if ($importType !== null) {
                        $imports->add($importType);
                        $interfaceImports->add($importType);
                    }
                }
            }

            $result[] = new ApiGroupContext(
                tag: $tag,
                className: $className,
                namespace: $apiNamespace,
                operations: $operations,
                imports: $imports,
                interfaceImports: $interfaceImports,
                securitySchemes: $groupSchemes,
                allSecuritySchemes: $securitySchemes,
            );
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    private function buildOperationContext(
        string $path,
        string $httpMethod,
        Operation $operation,
        Components $components,
        ?array $security = null,
    ): OperationContext {
        $operationId = $operation->operationId !== null
            ? lcfirst($this->naming->className($operation->operationId))
            : $httpMethod . $this->naming->className(str_replace(['/', '{', '}'], '_', $path));

        [$pathParams, $queryParams, $headerParams, $cookieParams] = $this->resolveParameters(
            $operation->parameters,
            $components,
        );

        $pathParamsByWireName = [];
        foreach ($pathParams as $pathParam) {
            $pathParamsByWireName[$pathParam->wireName] = $pathParam;
        }

        $pathParts = preg_split('/\{([^}]+)}/', $path, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$path];
        $phpPath = var_export(array_shift($pathParts) ?? '', true);
        while ($pathParts !== []) {
            $parameterName = (string) array_shift($pathParts);
            $literal = array_shift($pathParts) ?? '';
            $pathParam = $pathParamsByWireName[$parameterName] ?? null;
            $valueExpression = $pathParam !== null
                ? $pathParam->wireValueExpression()
                : '$' . $this->naming->propertyName($parameterName);
            $needsCast = true;
            if ($pathParam !== null && $pathParam->shortValueType() !== null && !$pathParam->isArray()) {
                $valueExpression .= '->value';
            } elseif ($pathParam !== null && $pathParam->valueType === 'bool' && !$pathParam->isArray()) {
                $valueExpression = '(' . $valueExpression . " ? 'true' : 'false')";
                $needsCast = false;
            } elseif ($pathParam !== null && $pathParam->format !== null) {
                $needsCast = false;
            }
            $phpPath .= ' . rawurlencode(' . ($needsCast ? '(string) ' : '') . $valueExpression . ')';
            if ($literal !== '') {
                $phpPath .= ' . ' . var_export($literal, true);
            }
        }

        [$requestBodyType, $requestBodyRequired] = $this->resolveRequestBody(
            $operation,
            $components,
        );

        [$returnType, $returnsArray, $successCodes, $errorCodes, $successResponses, $returnTypes, $returnsNullable, $errorResponses] = $this->resolveReturnType(
            $operation,
            $components,
        );

        return new OperationContext(
            operationId: $operationId,
            httpMethod: $httpMethod,
            path: $path,
            phpPath: $phpPath,
            pathParams: $pathParams,
            queryParams: $queryParams,
            headerParams: $headerParams,
            cookieParams: $cookieParams,
            requestBodyType: $requestBodyType,
            requestBodyRequired: $requestBodyRequired,
            returnType: $returnType,
            returnsArray: $returnsArray,
            summary: $operation->summary,
            description: $operation->description,
            deprecated: $operation->deprecated,
            tags: $operation->tags,
            successCodes: $successCodes,
            errorCodes: $errorCodes,
            operation: $operation,
            successResponses: $successResponses,
            returnTypes: $returnTypes,
            returnsNullable: $returnsNullable,
            errorResponses: $errorResponses,
            security: $security,
        );
    }

    /**
     * Resolve the effective security requirement alternatives of an operation, limited to supported schemes.
     *
     * @param array<array<string, string[]>>|null $globalSecurity
     * @param array<string, SecuritySchemeContext> $schemes
     *
     * @return list<list<string>>|null null when the operation needs no authentication
     */
    private function resolveSecurity(Operation $operation, ?array $globalSecurity, array $schemes): ?array
    {
        $requirements = $operation->security ?? $globalSecurity ?? [];
        $alternatives = [];
        foreach ($requirements as $requirement) {
            if (!is_array($requirement)) {
                continue;
            }
            $names = array_map('strval', array_keys($requirement));
            foreach ($names as $name) {
                if (!isset($schemes[$name])) {
                    continue 2;
                }
            }
            $alternatives[] = $names;
        }

        if ($alternatives === []) {
            return null;
        }

        return $alternatives;
    }

    /**
     * @return array<string, SecuritySchemeContext>
     */
    private function buildSecuritySchemes(Components $components): array
    {
        $schemes = [];
        foreach ($components->securitySchemes as $name => $scheme) {
            $name = (string) $name;
            $propertyName = lcfirst($this->naming->className($name));
            $httpScheme = strtolower((string) $scheme->scheme);
            $context = match (true) {
                $scheme->type === 'http' && $httpScheme === 'basic' => new SecuritySchemeContext($name, $propertyName, SecuritySchemeContext::KIND_BASIC, description: $scheme->description),
                $scheme->type === 'http' && $httpScheme === 'bearer',
                $scheme->type === 'oauth2',
                $scheme->type === 'openIdConnect' => new SecuritySchemeContext($name, $propertyName, SecuritySchemeContext::KIND_BEARER, description: $scheme->description),
                $scheme->type === 'http' && $httpScheme !== '' => new SecuritySchemeContext($name, $propertyName, SecuritySchemeContext::KIND_HTTP, httpScheme: ucfirst($httpScheme), description: $scheme->description),
                $scheme->type === 'apiKey' && in_array($scheme->in, ['header', 'query', 'cookie'], true) && (string) $scheme->name !== '' => new SecuritySchemeContext($name, $propertyName, SecuritySchemeContext::KIND_API_KEY, in: $scheme->in, parameterName: $scheme->name, description: $scheme->description),
                default => null,
            };
            if ($context !== null) {
                $schemes[$name] = $context;
            }
        }

        return $schemes;
    }

    /**
     * @param Parameter[] $parameters
     *
     * @return array{ParameterContext[], ParameterContext[], ParameterContext[], ParameterContext[]}
     */
    private function resolveParameters(array $parameters, Components $components): array
    {
        $path = [];
        $query = [];
        $header = [];
        $cookie = [];

        foreach ($parameters as $param) {
            $phpType = $param->schema !== null
                ? $this->schemaToPhpType($param->schema, $components)
                : 'string';
            [$format, $valueType] = $param->schema !== null
                ? $this->parameterValueType($param->schema, $components)
                : [null, 'string'];

            if ($format !== null) {
                $phpType = str_ends_with($phpType, '[]') ? '\DateTimeInterface[]' : '\DateTimeInterface';
            }

            $docType = null;
            if (str_ends_with($phpType, '[]')) {
                $itemType = substr($phpType, 0, -2);
                $docType = 'list<' . (str_contains($itemType, '\\') ? '\\' . ltrim($itemType, '\\') : $itemType) . '>';
                $phpType = 'array';
            }

            if (!$param->required && !str_starts_with($phpType, '?')) {
                $phpType = '?' . $phpType;
                if ($docType !== null) {
                    $docType .= '|null';
                }
            }

            $defaultStyle = in_array($param->in, ['query', 'cookie'], true) ? 'form' : 'simple';
            $style = $param->style ?? $defaultStyle;

            $ctx = new ParameterContext(
                name: lcfirst($this->naming->className($param->name)),
                wireName: $param->name,
                phpType: $phpType,
                required: $param->required,
                in: $param->in,
                description: $param->description,
                style: $style,
                explode: $param->explode ?? ($style === 'form'),
                docType: $docType,
                format: $format,
                valueType: $valueType,
                deprecated: $param->deprecated,
            );

            match ($param->in) {
                'path'   => $path[] = $ctx,
                'query'  => $query[] = $ctx,
                'header' => $header[] = $ctx,
                'cookie' => $cookie[] = $ctx,
                default  => null,
            };
        }

        return [$path, $query, $header, $cookie];
    }

    /**
     * @return array{string|null, bool}
     */
    private function resolveRequestBody(Operation $operation, Components $components): array
    {
        if ($operation->requestBody === null) {
            return [null, false];
        }

        $mediaType = null;
        foreach ($operation->requestBody->content as $contentType => $candidate) {
            if (strtolower(trim(explode(';', $contentType, 2)[0])) === 'application/json') {
                $mediaType ??= $candidate;

                continue;
            }

            if ($candidate !== null) {
                throw new \InvalidArgumentException(sprintf(
                    'Request content type "%s" is not supported for operation "%s"; only application/json is supported.',
                    $contentType,
                    $operation->operationId ?? '(unnamed)',
                ));
            }
        }

        if ($mediaType === null || $mediaType->schema === null) {
            return ['array', $operation->requestBody->required];
        }

        $type = $this->schemaToPhpType($mediaType->schema, $components);

        return [$type, $operation->requestBody->required];
    }

    /**
     * @return array{string, bool, string[], string[], array<string, string|null>, string[], bool, array<string, string|null>}
     *     [returnType, returnsArray, successCodes, errorCodes, successResponses, returnTypes, returnsNullable, errorResponses]
     */
    private function resolveReturnType(Operation $operation, Components $components): array
    {
        $successCodes = [];
        $errorCodes = [];
        $successResponses = [];
        $errorResponses = [];

        foreach ($operation->responses as $statusCode => $response) {
            $code = (string) $statusCode;
            $isSuccess = $code !== 'default'
                && (int) $code >= 200 && (int) $code < 300;

            if ($isSuccess) {
                $successCodes[] = $code;
                $successResponses[$code] = $this->resolveResponseType($response->content['application/json'] ?? null, $components);
            } else {
                $errorCodes[] = $code;
                $errorType = $this->resolveResponseType($response->content['application/json'] ?? null, $components);
                $errorResponses[$code] = $errorType !== null && str_contains($errorType, '\\') && !str_ends_with($errorType, '[]')
                    ? $errorType
                    : null;
            }
        }

        // Without explicit 2xx responses, the `default` response describes successful responses too.
        if ($successResponses === [] && isset($operation->responses['default'])) {
            unset($errorResponses['default']);
            $successResponses['default'] = $this->resolveResponseType(
                $operation->responses['default']->content['application/json'] ?? null,
                $components,
            );
        }

        $uniqueTypes = array_values(array_unique(array_filter(
            $successResponses,
            static fn (?string $type): bool => $type !== null,
        )));
        $returnsNullable = $uniqueTypes !== [] && in_array(null, $successResponses, true);
        $returnsArray = false;

        if (empty($uniqueTypes)) {
            $returnType = 'void';
        } elseif (count($uniqueTypes) === 1) {
            $returnType = $uniqueTypes[0];
            $returnsArray = str_ends_with($returnType, '[]');
        } else {
            $allModels = array_filter(
                $uniqueTypes,
                static fn (string $type): bool => str_contains($type, '\\') && !str_ends_with($type, '[]'),
            );
            // Distinct model types become a union hydrated by status code; anything else stays mixed.
            if (count($allModels) === count($uniqueTypes) && count($uniqueTypes) <= 4) {
                $returnType = implode('|', $uniqueTypes);
            } else {
                $returnType = 'mixed';
                $uniqueTypes = [];
                $returnsNullable = false;
            }
        }

        return [$returnType, $returnsArray, $successCodes, $errorCodes, $successResponses, $uniqueTypes, $returnsNullable, $errorResponses];
    }

    private function resolveResponseType(?MediaType $mediaType, Components $components): ?string
    {
        if ($mediaType?->schema === null) {
            return null;
        }

        $type = $this->schemaToPhpType($mediaType->schema, $components);

        return $type === 'mixed' ? null : $type;
    }

    /**
     * Resolve the scalar (item) value type of a parameter schema.
     *
     * @return array{string|null, string} [format ('date'|'date-time'|null), valueType]
     */
    private function parameterValueType(Schema $schema, Components $components): array
    {
        if ($schema->ref !== null) {
            $refName = $this->extractRefName($schema->ref);
            $kind = $this->resolver->getKind($refName);
            if ($kind === SchemaKind::Enum) {
                return [null, $this->naming->modelNamespace() . '\\' . $this->naming->enumName($refName)];
            }
            $target = $components->schemas[$refName] ?? null;

            return $target !== null ? $this->parameterValueType($target, $components) : [null, 'string'];
        }

        if ($schema->type === 'array') {
            return $schema->items !== null ? $this->parameterValueType($schema->items, $components) : [null, 'string'];
        }

        if ($schema->type === 'string' && in_array($schema->format, ['date', 'date-time'], true)) {
            return [$schema->format, $schema->format];
        }

        return [null, match ($schema->type) {
            'integer' => 'int',
            'number'  => 'float',
            'boolean' => 'bool',
            default   => 'string',
        }];
    }

    private function schemaToPhpType(Schema $schema, Components $components): string
    {
        if ($schema->ref !== null) {
            $refName = $this->extractRefName($schema->ref);
            $kind = $this->resolver->getKind($refName);

            // Alias schemas are not generated as classes — follow through to the
            // underlying type (e.g. `Pets` is `type: array, items: Pet`).
            if ($kind === SchemaKind::Alias) {
                $aliasSchema = $components->schemas[$refName] ?? null;
                if ($aliasSchema !== null) {
                    return $this->schemaToPhpType($aliasSchema, $components);
                }
            }

            $modelNs = $this->naming->modelNamespace();

            return $modelNs . '\\' . match ($kind) {
                SchemaKind::Enum      => $this->naming->enumName($refName),
                SchemaKind::Interface => $this->naming->interfaceName($refName),
                default               => $this->naming->className($refName),
            };
        }

        if ($schema->type === 'array') {
            if ($schema->items !== null) {
                return $this->schemaToPhpType($schema->items, $components) . '[]';
            }

            return 'array';
        }

        return match ($schema->type) {
            'integer' => 'int',
            'number'  => 'float',
            'boolean' => 'bool',
            'string'  => 'string',
            default   => 'mixed',
        };
    }

    private function resolveApiNamespace(): string
    {
        if ($this->config->apiNamespace !== '') {
            return $this->config->apiNamespace;
        }

        // Default: modelNamespace parent + '\Api'
        $parts = explode('\\', rtrim($this->config->modelNamespace, '\\'));
        array_pop($parts);

        return implode('\\', $parts) . '\\Api';
    }

    private function extractRefName(string $ref): string
    {
        return (string) (array_reverse(explode('/', $ref))[0] ?? '');
    }
}
