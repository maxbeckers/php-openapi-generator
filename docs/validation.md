# Validation Strategies

Validation generation is controlled by `GeneratorConfig::$validationStrategy`.

## Available Strategies

- `ValidationStrategy::None` (default): no validation code generated
- `ValidationStrategy::SymfonyConstraints`: emits Symfony Validator attributes
- `ValidationStrategy::NativeMethod`: emits `validate()` and `validateOrThrow()` methods

## Validation Toggle Points

You can enable validation at four points:

- `$config->validateServerRequest`
- `$config->validateServerResponse`
- `$config->validateClientRequest`
- `$config->validateClientResponse`

These toggles are only active when `validationStrategy` is not `ValidationStrategy::None`.

- For `ValidationStrategy::NativeMethod`, generated code calls `validateOrThrow()`.
- For `ValidationStrategy::SymfonyConstraints`, generated code validates DTOs via Symfony Validator with attribute mapping enabled.

Recommended default:

- enable server request validation
- keep response/client validation off unless you need stricter contract checks

## Example Configuration

```php
<?php

declare(strict_types=1);

use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Config\ValidationStrategy;

$config = new GeneratorConfig();
$config->validationStrategy = ValidationStrategy::NativeMethod;
$config->validateServerRequest = true;
$config->validateClientRequest = false;
$config->validateClientResponse = false;
$config->validateServerResponse = false;

return $config;
```

## SymfonyConstraints Strategy

Generates property attributes such as:

- `#[Assert\\NotNull]`
- `#[Assert\\Length(...)]`
- `#[Assert\\Range(...)]`
- `#[Assert\\Regex(...)]`

This requires `symfony/validator` at runtime where validation executes.

When one of the request/response validation flags is enabled, generated server/client API classes include runtime checks that validate DTO payloads before/after transport.

Generated API classes also expose a DI-friendly constructor argument:

- `?ValidatorInterface $validator = null`

If you pass a validator from your container, it is used directly. If omitted, the generator falls back to `Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()`.

## NativeMethod Strategy

Generates methods directly on DTOs:

```php
/** @return array<string> */
public function validate(): array
{
    $errors = [];
    // generated checks...
    return $errors;
}

public function validateOrThrow(): void
{
    $errors = $this->validate();
    if ($errors !== []) {
        throw new \InvalidArgumentException(implode('; ', $errors));
    }
}
```

Use this strategy when you want zero extra framework dependencies.

When one of the request/response validation flags is enabled, generated server/client API classes call `validateOrThrow()` for request payloads and/or response DTOs.

