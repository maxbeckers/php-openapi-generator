# Configuration Reference

This page covers the most important options in `php-openapi-generator.php`.

## Core Inputs

- `$config->specFile` (default: `openapi.yaml`)
- `$config->outputDir` (default: `generated`)
- `$config->phpVersion` (default: `PHP_VERSION`, the PHP version running the generator)

### PHP Version and Template Fallback

You can leave `phpVersion` unset or override it directly or with
`$config->setPhpVersion('8.5')`. Any target version can be configured; it does not
need a matching template directory. Full versions such as `8.4.22` are supported.

Template selection uses the major and minor version, ignoring the patch level:

1. Use the matching directory if available (for example, `8.2.30` uses `templates/php82`).
2. Otherwise, use the highest available template version not newer than the target.
3. If all available templates are newer than the target, use the highest available template version.

Currently, only `templates/php82` is shipped. Running the generator on PHP
`8.4.22` without configuring `phpVersion`, or explicitly setting it to `8.5`,
therefore uses the PHP 8.2 templates. The configured version is not changed by
template fallback.

Setting an older target does not make these templates compatible with older PHP:
the generator and the shipped templates require PHP 8.2 or newer.

## Namespaces and Output Directories

- `$config->modelNamespace` (default: `Generated\\Model`)
- `$config->modelOutputDir` (default: `Model`)
- `$config->apiNamespace` (default: empty)
- `$config->apiOutputDir` (default: empty)

## Server vs Client Generation

- `$config->generationTarget = GenerationTarget::Server|Client`
- `$config->frameworkTarget = FrameworkTarget::None|Symfony|Laravel` (server mode)
- `$config->httpClient = HttpClientAdapter::SymfonyHttpClient|Guzzle|Psr18` (client mode)

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

- `$config->strictSpecValidation` (default: `false`): promote pre-generation
  compatibility warnings to errors. Also available as `--strict` on `generate`
  and `validate`; the CLI flag enables strict mode without mutating the config
  file. Structural/reference failures and unsafe collisions always fail.
- `$config->validationStrategy` (`ValidationStrategy::None|SymfonyConstraints|NativeMethod|LaravelValidation`)
- `$config->validateServerRequest`
- `$config->validateServerResponse`
- `$config->validateClientRequest`
- `$config->validateClientResponse`

These four flags inject runtime validation calls in generated server/client API classes when `validationStrategy` is `ValidationStrategy::NativeMethod`, `ValidationStrategy::SymfonyConstraints`, or `ValidationStrategy::LaravelValidation`.

See [Validation Strategies](./validation.md) for behavior and recommended defaults.
Preflight checks are independent of these runtime flags. Run
`vendor/bin/openapi-gen validate --strict` to check generation input without
creating generated output.

## Operation Filtering

Generate only selected operations and their reachable schema graph:

- `$config->includeTags`, `$config->excludeTags`
- `$config->includeOperationIds`, `$config->excludeOperationIds`
- `$config->includePaths`, `$config->excludePaths`

## Plugin and Runtime Controls

- `$config->autoGenerate` (run on Composer hook)
- `$config->addPlugin` (enable plugin hook integration)
- `$config->typedErrorResponses` (client: throw `Exception\ApiException` subclasses per error status, see [Client Generation](./client-generation.md#typed-error-responses))
- `$config->generateSecuritySchemes` (client: generate `ApiCredentials` and apply security schemes, see [Client Generation](./client-generation.md#authentication))

For `x-*` extension usage and custom plugin hooks, see [Extension Plugins](./plugins.md).

The Composer hook does nothing when no `php-openapi-generator.php` file is
present. Set either `autoGenerate=false` or `addPlugin=false` to disable
generation from `post-autoload-dump`.

Generation overwrites files with matching generated paths but does not remove
stale files left by schemas or operations removed from the specification. Run
`vendor/bin/openapi-gen clean` before generation when stale output must be
removed.

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

$config->phpReadonly = true;
$config->validationStrategy = ValidationStrategy::SymfonyConstraints;
$config->validateServerRequest = true;

// Client-only example when generating HTTP clients instead of server glue:
// $config->generationTarget = GenerationTarget::Client;
// $config->httpClient = HttpClientAdapter::Guzzle;

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
