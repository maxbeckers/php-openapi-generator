<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Generator;

use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Generator\Context\ApiGroupContext;
use MaxBeckers\OpenApiGenerator\Generator\Context\OperationContext;
use MaxBeckers\OpenApiGenerator\Generator\Context\ParameterContext;
use MaxBeckers\OpenApiGenerator\Spec\Components;
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

        foreach ($spec->paths as $path => $pathItem) {
            foreach ($pathItem->getOperations() as $httpMethod => $operation) {
                $tag = $operation->tags[0] ?? 'Default';
                $opCtx = $this->buildOperationContext(
                    $path,
                    $httpMethod,
                    $operation,
                    $spec->components,
                );
                $groups[$tag][] = $opCtx;
            }
        }

        $apiNamespace = $this->resolveApiNamespace();
        $result = [];

        foreach ($groups as $tag => $operations) {
            $className = $this->naming->className($tag . 'Api');
            $imports = new ImportManager($apiNamespace);

            // Register all return/param types with the import manager
            foreach ($operations as $op) {
                if ($op->requestBodyType !== null && str_contains($op->requestBodyType, '\\')) {
                    $imports->add($op->requestBodyType);
                }
                if ($op->returnType !== 'void' && $op->returnType !== 'mixed' && str_contains($op->returnType, '\\')) {
                    $type = $op->returnsArray ? rtrim($op->returnType, '[]') : $op->returnType;
                    $imports->add($type);
                }
                foreach ($op->allParams() as $param) {
                    if (str_contains($param->phpType, '\\')) {
                        $imports->add($param->phpType);
                    }
                }
            }

            $result[] = new ApiGroupContext(
                tag: $tag,
                className: $className,
                namespace: $apiNamespace,
                operations: $operations,
                imports: $imports,
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
    ): OperationContext {
        $operationId = $operation->operationId !== null
            ? lcfirst($this->naming->className($operation->operationId))
            : $httpMethod . $this->naming->className(str_replace(['/', '{', '}'], '_', $path));

        $phpPathTemplate = preg_replace('/\{([^}]+)}/', '{$$1}', $path) ?? $path;
        $phpPath = '"' . $phpPathTemplate . '"';

        [$pathParams, $queryParams, $headerParams] = $this->resolveParameters(
            $operation->parameters,
            $components,
        );

        [$requestBodyType, $requestBodyRequired] = $this->resolveRequestBody(
            $operation,
            $components,
        );

        [$returnType, $returnsArray, $successCodes, $errorCodes] = $this->resolveReturnType(
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
        );
    }

    /**
     * @param Parameter[] $parameters
     *
     * @return array{ParameterContext[], ParameterContext[], ParameterContext[]}
     */
    private function resolveParameters(array $parameters, Components $components): array
    {
        $path = [];
        $query = [];
        $header = [];

        foreach ($parameters as $param) {
            $phpType = $param->schema !== null
                ? $this->schemaToPhpType($param->schema, $components)
                : 'string';

            if (!$param->required && !str_starts_with($phpType, '?')) {
                $phpType = '?' . $phpType;
            }

            $ctx = new ParameterContext(
                name: lcfirst($this->naming->className($param->name)),
                wireName: $param->name,
                phpType: $phpType,
                required: $param->required,
                in: $param->in,
                description: $param->description,
            );

            match ($param->in) {
                'path'   => $path[] = $ctx,
                'query'  => $query[] = $ctx,
                'header' => $header[] = $ctx,
                default  => null,
            };
        }

        return [$path, $query, $header];
    }

    /**
     * @return array{string|null, bool}
     */
    private function resolveRequestBody(Operation $operation, Components $components): array
    {
        if ($operation->requestBody === null) {
            return [null, false];
        }

        $mediaType = $operation->requestBody->content['application/json'] ?? null;
        if ($mediaType === null || $mediaType->schema === null) {
            return ['array', $operation->requestBody->required];
        }

        $type = $this->schemaToPhpType($mediaType->schema, $components);

        return [$type, $operation->requestBody->required];
    }

    /**
     * @return array{string, bool, string[], string[]}  [returnType, returnsArray, successCodes, errorCodes]
     */
    private function resolveReturnType(Operation $operation, Components $components): array
    {
        $successCodes = [];
        $errorCodes = [];
        $successTypes = [];

        foreach ($operation->responses as $statusCode => $response) {
            $code = (string) $statusCode;
            $isSuccess = $code !== 'default'
                && (int) $code >= 200 && (int) $code < 300;

            if ($isSuccess) {
                $successCodes[] = $code;
                $mediaType = $response->content['application/json'] ?? null;
                if ($mediaType?->schema !== null) {
                    $type = $this->schemaToPhpType($mediaType->schema, $components);
                    if ($type !== 'mixed') {
                        $successTypes[] = $type;
                    }
                }
            } else {
                $errorCodes[] = $code;
            }
        }

        $uniqueTypes = array_unique($successTypes);
        $returnsArray = false;

        if (empty($uniqueTypes)) {
            $returnType = 'void';
        } elseif (count($uniqueTypes) === 1) {
            $returnType = $uniqueTypes[0];
            $returnsArray = str_ends_with($returnType, '[]');
        } else {
            // Multiple distinct return types — emit a union if ≤ 4, else mixed
            if (count($uniqueTypes) <= 4) {
                $returnType = implode('|', $uniqueTypes);
            } else {
                $returnType = 'mixed';
            }
        }

        return [$returnType, $returnsArray, $successCodes, $errorCodes];
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
