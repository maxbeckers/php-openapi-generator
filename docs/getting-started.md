# Getting Started

This guide gets you from an OpenAPI spec to generated PHP models and API code in a few minutes.

It targets teams that want a PHP-only workflow: a small dev dependency, generated code committed or regenerated in CI, and no extra JS codegen toolchain.

## 1) Install the package

```bash
composer require --dev maxbeckers/php-openapi-generator
```

Allow the Composer plugin in your root `composer.json`:

```json
{
  "config": {
    "allow-plugins": {
      "maxbeckers/php-openapi-generator": true
    }
  }
}
```

## 2) Add `php-openapi-generator.php`

Create this config file in your project root:

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

// Optional when framework-specific majors need different generated glue:
// $config->frameworkVersion = '8.0';

// Optional when generating clients and adapter majors need different code:
// $config->httpClient = HttpClientAdapter::Guzzle;
// $config->httpClientVersion = '7.8';

return $config;
```

## 3) Generate code

```bash
vendor/bin/openapi-gen
```

## 4) Pick your target style

- `GenerationTarget::Server`: generates API contracts from `paths`.
- `GenerationTarget::Client`: generates typed API client classes.
- Optional version knobs: `$config->frameworkVersion` for server framework majors and `$config->httpClientVersion` for client adapter majors.

Useful CLI overrides:

```bash
vendor/bin/openapi-gen --target=server --framework=none
vendor/bin/openapi-gen --target=server --framework=symfony
vendor/bin/openapi-gen --target=server --framework=laravel
vendor/bin/openapi-gen --target=client --http-client=symfony
vendor/bin/openapi-gen --target=client --http-client=guzzle
vendor/bin/openapi-gen --target=client --http-client=psr18
```

## 5) Integrate generated code

- Server mode: implement generated `*ApiInterface` methods.
- Client mode: inject generated `*ApiClient` into your services.
- Model DTOs: use `fromArray()` for hydration and `toArray()` for serialization.

If you want a framework-first path, jump to:

- [Server Quickstart](./quickstart-server.md)
- [Client Quickstart](./quickstart-client.md)

## 6) Regenerate on spec changes

Run generation each time your OpenAPI spec changes. You can run it explicitly with `vendor/bin/openapi-gen` or rely on the Composer plugin hook in your workflow.

## Next Docs

- [Server Quickstart](./quickstart-server.md)
- [Client Quickstart](./quickstart-client.md)
- [Framework Targets](./framework-targets.md)
- [Client Generation](./client-generation.md)
- [HTTP Client Adapters](./http-client-adapters.md)
- [Validation Strategies](./validation.md)
- [Extension Plugins (`x-*`)](./plugins.md)
- [Configuration Reference](./configuration-reference.md)
