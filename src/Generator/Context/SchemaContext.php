<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Generator\Context;

use MaxBeckers\OpenApiGenerator\Generator\ImportManager;
use MaxBeckers\OpenApiGenerator\Generator\SchemaKind;
use MaxBeckers\OpenApiGenerator\Spec\Schema;

/**
 * All information about a single schema needed by templates and plugins.
 */
class SchemaContext
{
    /**
     * @param string $schemaName Original OAS component name (e.g. "Pet")
     * @param string $className Generated PHP class/enum/interface name (with prefix/suffix)
     * @param string $namespace Fully-qualified PHP namespace for the generated file
     * @param SchemaKind $kind What PHP construct to generate
     * @param Schema $schema The resolved spec schema
     * @param PropertyContext[] $properties Resolved + ordered property list
     * @param string[] $circularProperties Property names involved in circular refs
     * @param string|null $parentClass FQCN of parent class (from allOf single-$ref pattern)
     * @param PropertyContext[] $parentConstructorProperties Constructor params expected by parent::__construct()
     * @param PropertyContext[] $constructorProperties Full constructor params for this class (inherited + own)
     * @param array<string, string> $discriminatorCases map of discriminator value => generated class short name
     * @param string[] $implementsInterfaces FQCNs of interfaces this class implements
     * @param string[] $unionTypes FQCNs for oneOf/anyOf without discriminator
     * @param ImportManager $imports Manages use-statement deduplication
     */
    public function __construct(
        public readonly string        $schemaName,
        public readonly string        $className,
        public readonly string        $namespace,
        public readonly SchemaKind    $kind,
        public readonly Schema        $schema,
        public array                  $properties,
        public array                  $circularProperties,
        public ?string                $parentClass,
        public array                  $parentConstructorProperties,
        public array                  $constructorProperties,
        public array                  $discriminatorCases,
        public array                  $implementsInterfaces,
        public array                  $unionTypes,
        public readonly ImportManager $imports,
    )
    {
    }
}
