<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Validation;

final class ValidationException extends \InvalidArgumentException
{
    public function __construct(public readonly DiagnosticBag $diagnostics)
    {
        parent::__construct("OpenAPI preflight failed:\n" . implode("\n", array_map(
            static fn (Diagnostic $diagnostic): string => (string) $diagnostic,
            $diagnostics->all(),
        )));
    }
}
