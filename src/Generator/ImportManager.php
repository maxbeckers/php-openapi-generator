<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Generator;

/**
 * Tracks imported class names for a single generated file and ensures
 * no alias collisions occur.
 *
 * Rules:
 * - Fully-qualified names in the same namespace are not imported (short name used).
 * - When two classes with the same short name are added, the second one keeps
 *   its fully-qualified form (no alias is generated to keep templates simple).
 */
class ImportManager
{
    /**
     * Map of short name → fully-qualified name for all accepted imports.
     *
     * @var array<string, string>
     */
    private array $imports = [];

    /**
     * Short names that have a collision — they will be written as FQCN inline.
     *
     * @var array<string, true>
     */
    private array $collisions = [];

    public function __construct(private readonly string $currentNamespace)
    {
    }

    /**
     * Register a fully-qualified class name.
     *
     * Returns the name to use in generated code:
     * - Short name when there is no collision.
     * - Fully-qualified name (with leading backslash) when there is a collision.
     */
    public function add(string $fqcn): string
    {
        // Strip leading backslash
        $fqcn = ltrim($fqcn, '\\');

        // Same namespace → no import needed, use short name
        $shortNs = rtrim($this->currentNamespace, '\\');
        $expectedFqcn = $shortNs . '\\' . $this->shortName($fqcn);
        if ($fqcn === $expectedFqcn || $this->namespaceOf($fqcn) === $shortNs) {
            return $this->shortName($fqcn);
        }

        // Built-in / global namespace
        if (!str_contains($fqcn, '\\')) {
            return $fqcn;
        }

        $short = $this->shortName($fqcn);

        if (!isset($this->imports[$short])) {
            $this->imports[$short] = $fqcn;

            return $short;
        }

        if ($this->imports[$short] === $fqcn) {
            // Already registered
            return $short;
        }

        // Collision — mark and return FQCN
        $this->collisions[$short] = true;

        return '\\' . $fqcn;
    }

    /**
     * Add a nullable type. Returns "?ShortName" or "ShortName|null".
     */
    public function addNullable(string $fqcn): string
    {
        return '?' . $this->add($fqcn);
    }

    /**
     * Add a union type from multiple FQCNs. Returns "A|B|C".
     *
     * @param string[] $fqcns
     */
    public function addUnion(array $fqcns): string
    {
        return implode('|', array_map(fn ($fqcn) => $this->add($fqcn), $fqcns));
    }

    /**
     * Return all use statements, sorted alphabetically.
     *
     * @return string[]
     */
    public function getUseStatements(): array
    {
        $statements = [];
        foreach ($this->imports as $short => $fqcn) {
            if (isset($this->collisions[$short])) {
                // Not emitted as a use statement; used inline as FQCN
                continue;
            }
            $statements[] = 'use ' . $fqcn . ';';
        }
        sort($statements);

        return $statements;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }

    private function namespaceOf(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        array_pop($parts);

        return implode('\\', $parts);
    }
}
