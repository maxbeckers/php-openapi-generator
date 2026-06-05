# Configuration Reference

This page covers the most important options in `php-openapi-generator.php`.

## Core Inputs

- `$config->specFile` (default: `openapi.yaml`)
- `$config->outputDir` (default: `generated`)
- `$config->phpVersion` (default: `8.2`)

## Namespaces and Output Directories

- `$config->modelNamespace` (default: `Generated\\Model`)
- `$config->modelOutputDir` (default: `Model`)
- `$config->apiNamespace` (default: empty)
- `$config->apiOutputDir` (default: empty)

## Server vs Client Generation

- `$config->generationTarget = GenerationTarget::Server|Client`
- `$config->frameworkTarget = FrameworkTarget::None|Symfony|Laravel` (server mode)
- `$config->frameworkVersion = '8.0'|'9.0'|'10.0'|'11.0'|...` (optional, server mode)
- `$config->httpClient = HttpClientAdapter::SymfonyHttpClient|Guzzle|Psr18` (client mode)
- `$config->httpClientVersion = '6.4'|'7.0'|'7.8'|...` (optional, client mode)

`frameworkVersion` and `httpClientVersion` are optional string values you can set when generated code must react to framework- or transport-specific breaking changes. They are intended for template selection and conditional generation logic, similar to how `$config->phpVersion` is already used for PHP-specific output.

## Model Generation Options

- `$config->generateConstructor` (default: `true`)
- `$config->phpReadonly` (default: `true`)
- `$config->generatePhpDoc` (default: `true`)
- `$config->generateFromArray` (default: `true`)
- `$config->generateToArray` (default: `true`)
- `$config->omitNullsInToArray` (default: `true`)
- `$config->splitReadWriteDtos` (default: `false`)

## Type and Naming Controls

- `$config->dateTimeClass` (default: `DateTimeImmutable::class`)
- `$config->dateClass` (default: `DateTimeImmutable::class`)
- `$config->typeMapping` (default: `[]`)
- `$config->propertyNaming` (default: `PropertyNaming::CamelCase`)
- `$config->classPrefix`, `$config->classSuffix`
- `$config->interfaceSuffix`, `$config->enumSuffix`
- `$config->reservedWordSuffix` (default: `_`)

## Validation Controls

- `$config->validationStrategy` (`ValidationStrategy::None|SymfonyConstraints|NativeMethod|LaravelValidation`)
- `$config->validateServerRequest`
- `$config->validateServerResponse`
- `$config->validateClientRequest`
- `$config->validateClientResponse`

These four flags inject runtime validation calls in generated server/client API classes when `validationStrategy` is `ValidationStrategy::NativeMethod`, `ValidationStrategy::SymfonyConstraints`, or `ValidationStrategy::LaravelValidation`.

See [Validation Strategies](./validation.md) for behavior and recommended defaults.

## Operation Filtering

Generate only selected operations and their reachable schema graph:

- `$config->includeTags`, `$config->excludeTags`
- `$config->includeOperationIds`, `$config->excludeOperationIds`
- `$config->includePaths`, `$config->excludePaths`

## Plugin and Runtime Controls

- `$config->autoGenerate` (run on Composer hook)
- `$config->addPlugin` (enable plugin hook integration)
- `$config->verbosePluginWarnings`
- `$config->typedErrorResponses`
- `$config->generateSecuritySchemes`

For `x-*` extension usage and custom plugin hooks, see [Extension Plugins](./plugins.md).

## Example Config

```php
<?php

declare(strict_types=1);

use MaxBeckers\OpenApiGenerator\Config\FrameworkTarget;
use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;
use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Config\HttpClientAdapter;
use MaxBeckers\OpenApiGenerator\Config\ValidationStrategy;

$config = new GeneratorConfig();

$config->specFile = 'openapi.yaml';
$config->outputDir = 'generated';

$config->modelNamespace = 'App\\Model';
$config->apiNamespace = 'App\\Api';

$config->generationTarget = GenerationTarget::Server;
$config->frameworkTarget = FrameworkTarget::Symfony;
$config->frameworkVersion = '8.0';

$config->phpReadonly = true;
$config->validationStrategy = ValidationStrategy::SymfonyConstraints;
$config->validateServerRequest = true;

// Client-only example when generating HTTP clients instead of server glue:
// $config->generationTarget = GenerationTarget::Client;
// $config->httpClient = HttpClientAdapter::Guzzle;
// $config->httpClientVersion = '7.8';

$config->includeTags = ['pets'];
$config->excludeOperationIds = ['deletePet'];

return $config;
```

## CLI Overrides

- `--config=<path>`
- `--target=server|client`
- `--framework=none|symfony|laravel`
- `--http-client=symfony|guzzle|psr18`

## Complete Option List

For all properties and defaults, see `src/Config/GeneratorConfig.php`.
