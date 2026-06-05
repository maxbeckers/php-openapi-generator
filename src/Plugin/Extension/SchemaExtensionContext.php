<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Plugin\Extension;

use MaxBeckers\OpenApiGenerator\Generator\Context\SchemaContext;

/**
 * Input passed to SchemaExtensionPluginInterface::process().
 */
readonly class SchemaExtensionContext
{
    /**
     * @param array<string, mixed> $extensions OAS x-* extensions on this schema
     */
    public function __construct(
        public SchemaContext $schema,
        public array $extensions,
    ) {
    }
}
