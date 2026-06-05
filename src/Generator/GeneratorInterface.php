<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Generator;

use MaxBeckers\OpenApiGenerator\Generator\Context\GenerationContext;

/**
 * Contract for a single-schema code generator.
 */
interface GeneratorInterface
{
    /**
     * Returns true when this generator is capable of generating code for the
     * given context (e.g. it handles Objects but not Enums).
     */
    public function canGenerate(GenerationContext $context): bool;

    /**
     * Generate code for the given context.
     *
     * @return array<string, string> map of output file path (relative to outputDir) → file content
     */
    public function generate(GenerationContext $context): array;

    /**
     * Return the output file path (relative to outputDir) for the primary file
     * produced by this generator.
     */
    public function getOutputPath(GenerationContext $context, string $filename): string;
}
