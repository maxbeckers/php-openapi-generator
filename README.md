# PHP OpenAPI Generator

[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Tests](https://github.com/maxbeckers/php-openapi-generator/workflows/Tests/badge.svg)](https://github.com/maxbeckers/php-openapi-generator/actions)

Generate strongly typed PHP 8.2+ models, server contracts, and API clients directly from OpenAPI 3.x specs.

This package is designed for teams that want generated code to feel like hand-written application code: explicit types, predictable method signatures, and minimal runtime magic.

## Why This Project

- **PHP-native workflow**: no npm toolchain required for day-to-day generation
- **Lightweight by design**: generate plain PHP classes/interfaces you can read and own
- **Framework-friendly**: Symfony and Laravel server glue, plus multiple client adapters
- **Great for contract-first teams**: keep OpenAPI as source of truth and regenerate safely

## Features

- Typed model generation from `components/schemas` (classes, enums, nested objects)
- Server contract generation from `paths` with selectable framework target (`None`, `Symfony`, `Laravel`)
- Client generation with pluggable HTTP adapters (`Symfony HttpClient`, `Guzzle`, `PSR-18`)
- Optional framework and adapter version flags for version-specific code generation when upstream APIs change
- DTO hydration/serialization helpers (`fromArray()` and `toArray()`)
- Operation filtering by tags, operation IDs, and path patterns
- Extension plugin system for `x-*` vendor extensions (for example `x-trim`)

## Requirements

- PHP `>=8.2`
- Composer `>=2.0`

## Installation

```bash
composer require --dev maxbeckers/php-openapi-generator
```

Because this package is a Composer plugin, allow it in `composer.json`:

```json
{
  "config": {
	"allow-plugins": {
	  "maxbeckers/php-openapi-generator": true
	}
  }
}
```

The CLI binary is available at `vendor/bin/openapi-gen`.

## Quick Start

### 1) Create `php-openapi-generator.php`

```php
<?php

declare(strict_types=1);

use MaxBeckers\OpenApiGenerator\Config\FrameworkTarget;
use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;
use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Config\HttpClientAdapter;

$config = new GeneratorConfig();

$config->specFile = 'openapi.yaml';
$config->outputDir = 'generated';

$config->modelNamespace = 'App\\Model';
$config->modelOutputDir = 'Model';

$config->apiNamespace = 'App\\Api';
$config->apiOutputDir = 'Api';

$config->generationTarget = GenerationTarget::Server;
$config->frameworkTarget = FrameworkTarget::None;

// Optional version-aware generation knobs:
// $config->frameworkVersion = '8.0';
// $config->httpClient = HttpClientAdapter::Guzzle;
// $config->httpClientVersion = '7.8';

$config->phpReadonly = true;
$config->generateFromArray = true;
$config->generateToArray = true;

return $config;
```

### 2) Generate code

```bash
vendor/bin/openapi-gen
```

### 3) Choose a practical quickstart path

#### Server quickstart (Symfony or Laravel)

```php
$config->generationTarget = GenerationTarget::Server;
$config->frameworkTarget = FrameworkTarget::Symfony; // or FrameworkTarget::Laravel
```

Then:

1. Generate code with `vendor/bin/openapi-gen`
2. Implement generated `*ApiInterface` methods
3. Keep generated controllers/routes as thin transport glue

#### Client quickstart (typed API consumers)

```php
$config->generationTarget = GenerationTarget::Client;
$config->httpClient = HttpClientAdapter::SymfonyHttpClient; // or Guzzle / Psr18
```

Then:

1. Generate code with `vendor/bin/openapi-gen`
2. Inject `*ApiClient` into your services
3. Use generated DTOs for request/response mapping

### 4) Optional overrides from CLI

```bash
vendor/bin/openapi-gen --target=server --framework=symfony
vendor/bin/openapi-gen --target=server --framework=laravel
vendor/bin/openapi-gen --target=client --http-client=psr18
```

## What Gets Generated

Models are always generated.

### Server target (`GenerationTarget::Server`)

- `FrameworkTarget::None`: `*ApiInterface` only (pure contract)
- `FrameworkTarget::Symfony`: `*ApiInterface` + generated `*ApiController` actions with `#[Route]`
- `FrameworkTarget::Laravel`: `*ApiInterface` + generated `*ApiController` + `*ApiRoutes` helper

Optional: set `$config->frameworkVersion` when server framework major versions require different generated glue.

### Client target (`GenerationTarget::Client`)

- `*ApiClientInterface`
- `*ApiClient`

Adapters:

- `HttpClientAdapter::SymfonyHttpClient`
- `HttpClientAdapter::Guzzle`
- `HttpClientAdapter::Psr18`

Optional: set `$config->httpClientVersion` when adapter major versions require different generated transport code.

## Recommended Workflow

1. Keep your OpenAPI spec in source control.
2. Regenerate code when the spec changes.
3. Implement generated server interfaces or inject generated clients.
4. Decide as a team whether to commit generated code or regenerate in CI.

## Examples

- `examples/petstore-client-symfony`
- `examples/petstore-server-symfony`

## Documentation

- [Docs Index](docs/index.md)
- [Getting Started](docs/getting-started.md)
- [Server Quickstart](docs/quickstart-server.md)
- [Client Quickstart](docs/quickstart-client.md)
- [Configuration Reference](docs/configuration-reference.md)
- [Framework Targets (Server)](docs/framework-targets.md)
- [Client Generation](docs/client-generation.md)
- [HTTP Client Adapters](docs/http-client-adapters.md)
- [Validation Strategies](docs/validation.md)
- [Extension Plugins (`x-*`)](docs/plugins.md)

## Development

```bash
composer test
composer cs
```

## License

MIT - see `LICENSE`.

---

Issues and feedback: [github.com/maxbeckers/php-openapi-generator/issues](https://github.com/maxbeckers/php-openapi-generator/issues)

---

**Built with ❤️ for PHP developers**
