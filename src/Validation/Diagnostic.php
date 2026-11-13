<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Validation;

final readonly class Diagnostic
{
    public function __construct(
        public string $code,
        public string $severity,
        public string $pointer,
        public string $message,
        public string $hint,
        public ?string $document = null,
    ) {
    }

    public function __toString(): string
    {
        return sprintf(
            '[%s] %s at %s#%s: %s %s',
            $this->severity,
            $this->code,
            $this->document ?? '(resolved document; original source unavailable)',
            $this->pointer,
            $this->message,
            $this->hint,
        );
    }

    public static function append(string $pointer, string $token): string
    {
        return $pointer . '/' . str_replace(['~', '/'], ['~0', '~1'], $token);
    }
}
