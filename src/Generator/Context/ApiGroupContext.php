<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Generator\Context;

use MaxBeckers\OpenApiGenerator\Generator\ImportManager;

/**
 * Groups all operations belonging to one tag (or 'default' if untagged).
 */
class ApiGroupContext
{
    /**
     * @param string             $tag          Tag name (used to derive class name)
     * @param string             $className    Generated class/interface base name
     * @param string             $namespace    PHP namespace for the generated file
     * @param OperationContext[] $operations
     * @param ImportManager      $imports
     */
    public function __construct(
        public readonly string $tag,
        public readonly string $className,
        public readonly string $namespace,
        public array $operations,
        public readonly ImportManager $imports,
    ) {
    }
}
