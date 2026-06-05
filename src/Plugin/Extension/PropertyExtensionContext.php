<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Plugin\Extension;

use MaxBeckers\OpenApiGenerator\Generator\Context\PropertyContext;

/**
 * Input passed to PropertyExtensionPluginInterface::process().
 */
readonly class PropertyExtensionContext
{
    /**
     * @param array<string, mixed> $extensions OAS x-* extensions on this property
     */
    public function __construct(
        public PropertyContext $property,
        public array $extensions,
    ) {
    }
}
