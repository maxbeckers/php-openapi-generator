# Client Quickstart

This is the fastest path from OpenAPI file to a typed PHP API client.

## Goal

Generate DTOs and API client classes, then call your API through generated methods instead of hand-written request code.

## 1) Minimal config

```php
<?php

declare(strict_types=1);

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

$config->generationTarget = GenerationTarget::Client;
$config->httpClient = HttpClientAdapter::SymfonyHttpClient; // or Guzzle / Psr18

return $config;
```

## 2) Generate

```bash
vendor/bin/openapi-gen
```

## 3) Use generated code

- Inject generated `*ApiClient` in your services.
- Call typed client methods per operation.
- Work with generated model DTOs for request/response payloads.

## Adapter notes

- `HttpClientAdapter::SymfonyHttpClient`: good default for Symfony projects
- `HttpClientAdapter::Guzzle`: fits existing Guzzle middleware stacks
- `HttpClientAdapter::Psr18`: portable, framework-neutral option

See also: [Client Generation](./client-generation.md) and [HTTP Client Adapters](./http-client-adapters.md).
