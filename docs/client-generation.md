# Client Generation

Use `GenerationTarget::Client` to generate typed API client classes from `paths` plus DTO models from `components/schemas`.

This keeps API client generation in a PHP-native workflow: no npm-based generators required.

## What Gets Generated

For each API group, the generator creates:

- `*ApiClientInterface` for mocking and test seams
- `*ApiClient` concrete implementation
- referenced model classes/enums under your model output directory

Typical output:

```text
generated/
|- Api/
|  |- PetsApiClientInterface.php
|  `- PetsApiClient.php
`- Model/
   |- Pet.php
   |- NewPet.php
   `- ...
```

## Basic Configuration

```php
<?php

declare(strict_types=1);

use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;
use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Config\HttpClientAdapter;

$config = new GeneratorConfig();

$config->specFile = 'openapi.yaml';
$config->outputDir = 'generated';
$config->generationTarget = GenerationTarget::Client;
$config->httpClient = HttpClientAdapter::SymfonyHttpClient;
$config->httpClientVersion = '7.0'; // optional, for adapter-specific breaking changes

$config->modelNamespace = 'App\\Model';
$config->apiNamespace = 'App\\Api';

return $config;
```

## Runtime Shape of Generated Clients

Generated client methods usually follow this flow:

1. Build URL from `baseUrl` and path template values.
2. Build query/body payload from typed inputs.
3. Execute HTTP request with selected adapter.
4. Map JSON response back into generated DTOs via `fromArray()`.

Shared behavior:

- nullable query parameters are omitted
- request DTOs are serialized with `toArray()`
- array responses are mapped with `array_map(...)`
- `204`/no-content operations return `void`

## Choosing an HTTP Adapter

- `HttpClientAdapter::SymfonyHttpClient`: default, compact code, great for Symfony ecosystems
- `HttpClientAdapter::Guzzle`: good if your stack already uses Guzzle middleware
- `HttpClientAdapter::Psr18`: framework-neutral and portable

Set `$config->httpClientVersion` if the generated transport code ever needs to distinguish between major adapter versions. This mirrors `$config->frameworkVersion` on the server side and keeps version-aware generation explicit in config rather than inferred from installed packages.

See [HTTP Client Adapters](./http-client-adapters.md) for concrete adapter examples.

## Optional Validation Hooks

If enabled in config, generated clients can validate request and response DTOs around HTTP calls.

See [Validation Strategies](./validation.md).
