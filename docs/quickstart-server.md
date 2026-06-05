# Server Quickstart

This is the fastest path from OpenAPI file to framework-ready server code.

## Goal

Generate typed models plus server API contracts/glue, then implement your business logic in generated interfaces.

## 1) Minimal config

```php
<?php

declare(strict_types=1);

use MaxBeckers\OpenApiGenerator\Config\FrameworkTarget;
use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;
use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;

$config = new GeneratorConfig();
$config->specFile = 'openapi.yaml';
$config->outputDir = 'generated';

$config->modelNamespace = 'App\\Model';
$config->modelOutputDir = 'Model';

$config->apiNamespace = 'App\\Api';
$config->apiOutputDir = 'Api';

$config->generationTarget = GenerationTarget::Server;
$config->frameworkTarget = FrameworkTarget::Symfony; // or FrameworkTarget::Laravel

return $config;
```

## 2) Generate

```bash
vendor/bin/openapi-gen
```

## 3) Use generated code

- Implement generated `*ApiInterface` methods.
- Keep generated controllers/routes as transport glue.
- Regenerate whenever `openapi.yaml` changes.

## Framework notes

- `FrameworkTarget::None`: contracts only (`*ApiInterface`)
- `FrameworkTarget::Symfony`: adds controller actions with route attributes
- `FrameworkTarget::Laravel`: adds controller actions and `*ApiRoutes` helper

See also: [Framework Targets](./framework-targets.md).
