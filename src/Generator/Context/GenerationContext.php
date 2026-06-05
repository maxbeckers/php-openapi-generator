<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Generator\Context;

use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;

/**
 * Top-level context passed to every generator and plugin.
 */
readonly class GenerationContext
{
    public function __construct(
        public GeneratorConfig $config,
        public SchemaContext $schema,
    ) {
    }
}
