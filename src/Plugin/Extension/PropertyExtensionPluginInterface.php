<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Plugin\Extension;

/**
 * Plugin that can post-process a single property context.
 *
 * Implementations are registered on ModelGenerator via addPropertyPlugin().
 * They are invoked for every property after the base PropertyContext is built,
 * and may mutate the SchemaContext (e.g. add use statements) as a side-effect.
 */
interface PropertyExtensionPluginInterface
{
    /**
     * Process the property context. May mutate $context->schema as a side-effect.
     *
     * Return a PropertyExtensionResult to inject extra code / attributes,
     * or null if this plugin makes no changes for the given property.
     */
    public function process(PropertyExtensionContext $context): ?PropertyExtensionResult;
}
