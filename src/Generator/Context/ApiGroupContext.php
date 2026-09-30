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
     * @param ImportManager      $interfaceImports
     * @param array<string, SecuritySchemeContext> $securitySchemes    Security schemes used by operations of this group
     * @param array<string, SecuritySchemeContext> $allSecuritySchemes All supported security schemes of the spec
     */
    public function __construct(
        public readonly string $tag,
        public readonly string $className,
        public readonly string $namespace,
        public array $operations,
        public readonly ImportManager $imports,
        public readonly ImportManager $interfaceImports,
        public readonly array $securitySchemes = [],
        public readonly array $allSecuritySchemes = [],
    ) {
    }

    /**
     * Whether any operation of this group has typed error status codes.
     */
    public function hasErrorExceptionClasses(): bool
    {
        foreach ($this->operations as $operation) {
            if ($operation->errorExceptionClasses() !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Security scheme kinds used by this group (bearer, basic, http, apiKey:{in}).
     *
     * @return list<string>
     */
    public function securityKinds(?OperationContext $operation = null): array
    {
        $schemes = $this->securitySchemes;
        if ($operation !== null) {
            $schemes = [];
            foreach ($operation->security ?? [] as $alternative) {
                foreach ($alternative as $schemeName) {
                    if (isset($this->securitySchemes[$schemeName])) {
                        $schemes[$schemeName] = $this->securitySchemes[$schemeName];
                    }
                }
            }
        }

        $kinds = [];
        foreach ($schemes as $scheme) {
            $kinds[] = $scheme->kind === SecuritySchemeContext::KIND_API_KEY ? 'apiKey:' . $scheme->in : $scheme->kind;
        }

        return array_values(array_unique($kinds));
    }
}
