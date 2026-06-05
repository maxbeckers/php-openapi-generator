<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Plugin\Extension;

/**
 * Result returned by a schema extension plugin.
 */
readonly class SchemaExtensionResult
{
    /**
     * @param string[] $extraMethods additional PHP method source code to append to the generated class
     * @param string[] $extraUses    additional use statements required by $extraMethods
     */
    public function __construct(
        public array $extraMethods = [],
        public array $extraUses = [],
    ) {
    }
}
