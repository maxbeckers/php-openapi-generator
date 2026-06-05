<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Config;

enum GenerationTarget: string
{
    /** Generate server-side controller interfaces and abstract controller stubs */
    case Server = 'server';

    /** Generate client-side HTTP service classes */
    case Client = 'client';
}
