<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Config;

enum ValidationStrategy: string
{
    /** No validation generated */
    case None = 'none';

    /** Generate validate() methods using symfony/validator constraints */
    case SymfonyConstraints = 'symfony_constraints';

    /** Generate validate() methods using native PHP assertions */
    case NativeMethod = 'native_method';

    /** Generate rules() method using Laravel validation rule format */
    case LaravelValidation = 'laravel_validation';
}
