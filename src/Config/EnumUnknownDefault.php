<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Config;

enum EnumUnknownDefault: string
{
    /** Return null for unknown enum values */
    case Null = 'null';

    /** Throw an exception for unknown enum values */
    case Throw = 'throw';

    /** Return the raw string value for unknown enum values */
    case Raw = 'raw';
}
