<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Config;

enum PropertyNaming: string
{
    /** Convert property names to camelCase (default) */
    case CamelCase = 'camelCase';

    /** Convert property names to snake_case */
    case SnakeCase = 'snake_case';

    /** Keep the original wire name unchanged */
    case Original = 'original';
}
