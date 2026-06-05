<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Config;

enum HttpClientAdapter: string
{
    /** Symfony HttpClient (symfony/http-client) — zero extra deps when Symfony is already present */
    case SymfonyHttpClient = 'symfony';

    /** Guzzle HTTP client (guzzlehttp/guzzle) */
    case Guzzle = 'guzzle';

    /** PSR-18 compliant HTTP client (psr/http-client) — fully framework-agnostic */
    case Psr18 = 'psr18';
}
