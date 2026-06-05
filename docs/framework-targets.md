# Framework Targets

`GenerationTarget::Server` always generates API contracts from your OpenAPI operations, then adds optional framework glue based on `FrameworkTarget`.

## Overview

| Target | Generated API Contract | Generated Controller Glue | Generated Route Helper | Best Fit |
|---|---|---|---|---|
| `FrameworkTarget::None` | `*ApiInterface` | No | No | Plain PHP, custom stacks, PSR-based frameworks |
| `FrameworkTarget::Symfony` | `*ApiInterface` | Yes (`#[Route]` actions) | No | Symfony apps |
| `FrameworkTarget::Laravel` | `*ApiInterface` | Yes (request/response actions) | Yes (`*ApiRoutes`) | Laravel apps |

## Shared Model Output

Regardless of framework target, schema components generate typed models and enums.

- `fromArray()` and `toArray()` can be generated.
- DTOs support readonly generation for PHP 8.2+.
- Type hints preserve nullability and array item types.

See [Readonly Model Classes](./readonly-classes.md).

## How To Choose

- Choose `None` if you want only contracts and implement HTTP handling yourself.
- Choose `Symfony` if you want generated route attributes and controller action wrappers.
- Choose `Laravel` if you want generated action wrappers plus route helper registration (great for Laravel teams adopting contract-first APIs without heavy platform frameworks).
- Set `$config->frameworkVersion` when the generated glue must track framework-specific breaking changes (for example Symfony 8 vs 9 or Laravel 10 vs 11).

## Common Configuration

```php
use MaxBeckers\OpenApiGenerator\Config\FrameworkTarget;
use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;

$config->generationTarget = GenerationTarget::Server;
$config->frameworkTarget = FrameworkTarget::Symfony; // none|symfony|laravel
$config->frameworkVersion = '8.0'; // optional, enables version-specific template logic
```

If you leave `$config->frameworkVersion` as `null`, the generator uses the generic templates for the selected framework target.

## Detailed Guides

- [Plain PHP (`FrameworkTarget::None`)](./framework-targets/plain-php.md)
- [Symfony Target](./framework-targets/symfony.md)
- [Laravel Target](./framework-targets/laravel.md)
