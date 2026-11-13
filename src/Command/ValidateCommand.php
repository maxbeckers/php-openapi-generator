<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Command;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'validate', description: 'Validate OpenAPI generation input without writing files')]
final class ValidateCommand extends GenerateCommand
{
    protected function isValidationOnly(): bool
    {
        return true;
    }
}
