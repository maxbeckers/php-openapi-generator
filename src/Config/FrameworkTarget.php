<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Config;

enum FrameworkTarget: string
{
    /** No framework coupling — plain PHP interfaces only */
    case None = 'none';

    /** Symfony: adds #[Route] attributes and Response wrappers */
    case Symfony = 'symfony';

    /** Laravel: adds Request/JsonResponse controller glue and route registration helpers */
    case Laravel = 'laravel';
}
