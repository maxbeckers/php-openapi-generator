<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Plugin\Extension;

/**
 * Plugin that can post-process an entire schema context.
 *
 * Implementations are registered on ModelGenerator via addSchemaPlugin().
 */
interface SchemaExtensionPluginInterface
{
    /**
     * Process the schema context. May mutate $context->schema as a side-effect.
     *
     * Return a SchemaExtensionResult to inject extra methods / use statements,
     * or null if this plugin makes no changes for the given schema.
     */
    public function process(SchemaExtensionContext $context): ?SchemaExtensionResult;
}
