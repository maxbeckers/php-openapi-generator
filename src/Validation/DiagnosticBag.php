<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Validation;

final class DiagnosticBag
{
    /** @var array<string, Diagnostic> */
    private array $diagnostics = [];

    public function add(Diagnostic $diagnostic): void
    {
        $key = implode("\0", [$diagnostic->document ?? '', $diagnostic->pointer, $diagnostic->code, $diagnostic->message]);
        $this->diagnostics[$key] = $diagnostic;
    }

    /** @return list<Diagnostic> */
    public function all(): array
    {
        $result = array_values($this->diagnostics);
        usort($result, static fn (Diagnostic $a, Diagnostic $b): int => [
            $a->document ?? '', $a->pointer, $a->code, $a->message,
        ] <=> [$b->document ?? '', $b->pointer, $b->code, $b->message]);

        return $result;
    }

    public function hasErrors(): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->severity === 'error') {
                return true;
            }
        }

        return false;
    }
}
