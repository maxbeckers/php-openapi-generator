<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Generator;

use MaxBeckers\OpenApiGenerator\Spec\Components;
use MaxBeckers\OpenApiGenerator\Spec\OpenApiSpec;
use MaxBeckers\OpenApiGenerator\Spec\Operation;
use MaxBeckers\OpenApiGenerator\Spec\Schema;

/**
 * Filters an OpenApiSpec to only include operations (and their schemas)
 * that match the configured include/exclude rules for tags, operationIds,
 * and path patterns.
 *
 * Returns a new OpenApiSpec — the original is not mutated.
 */
class OperationFilter
{
    /**
     * @param string[] $includeTags        Only keep operations with at least one of these tags (empty = all)
     * @param string[] $excludeTags        Remove operations that have any of these tags
     * @param string[] $includeOperationIds Only keep operations with these IDs (empty = all)
     * @param string[] $excludeOperationIds Remove operations with these IDs
     * @param string[] $includePaths       Only keep paths matching these fnmatch patterns (empty = all)
     * @param string[] $excludePaths       Remove paths matching these fnmatch patterns
     */
    public function filter(
        OpenApiSpec $spec,
        array $includeTags = [],
        array $excludeTags = [],
        array $includeOperationIds = [],
        array $excludeOperationIds = [],
        array $includePaths = [],
        array $excludePaths = [],
    ): OpenApiSpec {
        $filtered = clone $spec;
        $filtered->components = clone $spec->components;
        $filtered->paths = [];

        foreach ($spec->paths as $path => $pathItem) {
            if (!$this->pathMatches($path, $includePaths, $excludePaths)) {
                continue;
            }

            $filteredItem = clone $pathItem;

            foreach (['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'] as $method) {
                $op = $filteredItem->$method;
                if ($op === null) {
                    continue;
                }

                if (!$this->operationMatches($op, $includeTags, $excludeTags, $includeOperationIds, $excludeOperationIds)) {
                    $filteredItem->$method = null;
                }
            }

            if (!empty($filteredItem->getOperations())) {
                $filtered->paths[$path] = $filteredItem;
            }
        }

        // Remove schemas that are no longer reachable from the filtered operations.
        $this->pruneUnreachableSchemas($filtered);

        return $filtered;
    }

    /**
     * Remove component schemas that are not reachable from any surviving operation's
     * request bodies, responses, or parameters.
     */
    private function pruneUnreachableSchemas(OpenApiSpec $spec): void
    {
        $reachable = [];
        $components = $spec->components;

        // Collect all $ref names reachable from surviving operations.
        foreach ($spec->paths as $pathItem) {
            foreach ($pathItem->getOperations() as $op) {
                foreach ($op->parameters as $param) {
                    if ($param->schema !== null) {
                        $this->collectRefs($param->schema, $components, $reachable);
                    }
                }
                if ($op->requestBody !== null) {
                    foreach ($op->requestBody->content as $mediaType) {
                        if ($mediaType->schema !== null) {
                            $this->collectRefs($mediaType->schema, $components, $reachable);
                        }
                    }
                }
                foreach ($op->responses as $response) {
                    foreach ($response->content as $mediaType) {
                        if ($mediaType->schema !== null) {
                            $this->collectRefs($mediaType->schema, $components, $reachable);
                        }
                    }
                }
            }
        }

        // Keep only reachable schemas (preserve relative order / keys).
        $spec->components->schemas = array_intersect_key(
            $components->schemas,
            $reachable,
        );
    }

    /**
     * Walk a schema tree and collect all named component schema references.
     *
     * @param array<string, bool> $reachable accumulator (pass by reference)
     */
    private function collectRefs(Schema $schema, Components $components, array &$reachable): void
    {
        if ($schema->ref !== null) {
            $name = (string) (array_reverse(explode('/', $schema->ref))[0] ?? '');
            if ($name === '' || isset($reachable[$name])) {
                return;
            }
            $reachable[$name] = true;
            // Recurse into the target schema so transitive refs are included.
            if (isset($components->schemas[$name])) {
                $this->collectRefs($components->schemas[$name], $components, $reachable);
            }

            return;
        }

        // Walk all child schemas.
        foreach ($schema->properties as $prop) {
            $this->collectRefs($prop, $components, $reachable);
        }
        if ($schema->items !== null) {
            $this->collectRefs($schema->items, $components, $reachable);
        }
        foreach ([...$schema->allOf, ...$schema->oneOf, ...$schema->anyOf] as $sub) {
            $this->collectRefs($sub, $components, $reachable);
        }
        if ($schema->not !== null) {
            $this->collectRefs($schema->not, $components, $reachable);
        }
    }

    /**
     * @param string[] $includePaths
     * @param string[] $excludePaths
     */
    private function pathMatches(string $path, array $includePaths, array $excludePaths): bool
    {
        if (!empty($excludePaths)) {
            foreach ($excludePaths as $pattern) {
                if (fnmatch($pattern, $path)) {
                    return false;
                }
            }
        }

        if (!empty($includePaths)) {
            foreach ($includePaths as $pattern) {
                if (fnmatch($pattern, $path)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * @param string[] $includeTags
     * @param string[] $excludeTags
     * @param string[] $includeOperationIds
     * @param string[] $excludeOperationIds
     */
    private function operationMatches(
        Operation $op,
        array $includeTags,
        array $excludeTags,
        array $includeOperationIds,
        array $excludeOperationIds,
    ): bool {
        // Exclude by operationId
        if (!empty($excludeOperationIds) && $op->operationId !== null) {
            if (in_array($op->operationId, $excludeOperationIds, true)) {
                return false;
            }
        }

        // Exclude by tags
        if (!empty($excludeTags)) {
            foreach ($op->tags as $tag) {
                if (in_array($tag, $excludeTags, true)) {
                    return false;
                }
            }
        }

        // Include by operationId
        if (!empty($includeOperationIds)) {
            if ($op->operationId !== null && in_array($op->operationId, $includeOperationIds, true)) {
                return true;
            }

            // includeOperationIds is an allowlist; op not in the list → exclude
            return false;
        }

        // Include by tags
        if (!empty($includeTags)) {
            foreach ($op->tags as $tag) {
                if (in_array($tag, $includeTags, true)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }
}
