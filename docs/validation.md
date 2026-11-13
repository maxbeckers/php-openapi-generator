# Validation Strategies

## Pre-generation Validation

Preflight checks inspect the specification and configuration before generated
files are written. They are separate from runtime DTO validation and run even
when `validationStrategy` is `None`.

```bash
vendor/bin/openapi-gen validate
vendor/bin/openapi-gen validate --config=php-openapi-generator.php --strict
vendor/bin/openapi-gen generate --strict
```

`validate` accepts the same `--target`, `--framework`, and `--http-client`
overrides as `generate`. It exercises the same loading, filtering, context,
plugin, and in-memory rendering pipeline but does not create generated output
files or directories. Errors produce a non-zero exit code; warnings alone do
not. Plugin code is still executed and is responsible for its own side effects.

Diagnostics include a stable code, `error` or `warning` severity, an RFC 6901
JSON pointer, a message, a corrective hint, and the source document when known:

```text
[error] structure.type at openapi.yaml#/paths/~1pets/get/parameters: Expected a list. Correct the field type in the specification.
```

Independent problems are aggregated and sorted by source, pointer, and code.
Malformed parent fields suppress checks on their children. Root-document
structure is checked before resolving references; after reference resolution,
external schema shapes are checked before entering the typed loader. Reference
failures retain the referencing document and pointer, and resolved external
fields retain their original document/fragment. Diagnostics without known
provenance explicitly say that the original source is unavailable.

### Enforcement and Compatibility

- **Always errors:** malformed loader input, unreadable documents, invalid local
  references, unsupported reference forms, boolean JSON Schemas unsupported by
  the loader, unsafe generated name/file/symbol collisions, malformed filter/
  type-mapping entries, unsupported backed-enum values, and non-JSON request bodies.
- **Warnings by default:** unsupported OpenAPI versions, missing basic metadata
  or response declarations, unsupported schema keywords/type unions and
  response media types, invalid parameter/status declarations, and newly
  diagnosed configuration incompatibilities or unknown dynamic config options.
- Set `$config->strictSpecValidation = true` or use `--strict` to promote these
  compatibility warnings to errors. The current-major default is `false`.

Warnings preserve legacy generation behavior; they do **not** mean an
unsupported feature is generated correctly. Use strict mode in contract-first
CI. Existing errors such as non-JSON request bodies remain errors.

Structural checks apply to the complete input. Generation-capability checks
apply to retained operations and their selected component schemas after
operation filtering, so unsupported representations in excluded operations do
not block generation. Model-only generation does not apply API representation
checks.

These are focused checks for this generator, **not** full OpenAPI or JSON Schema
meta-schema validation. Schema validation keywords may need runtime enforcement
outside generated DTOs. Transport-level strict response matching is a separate,
future feature.

### Programmatic Use

```php
$diagnostics = $service->validate($config, $configDir);
foreach ($diagnostics->all() as $diagnostic) {
    echo $diagnostic, PHP_EOL;
}
if ($diagnostics->hasErrors()) {
    // Report the diagnostics and stop before generation.
}
```

`OpenApiService::generate()` returns the warning diagnostics on success and
throws `ValidationException` (an `InvalidArgumentException`) containing
`$exception->diagnostics` on preflight errors. The CLI and Composer hook report
warnings; programmatic callers should consume the returned bag. Unexpected
generator or plugin exceptions propagate rather than being disguised as
validation success.

Generation prepares contents before `writeAll()`, so preflight errors leave
existing generated output unchanged. This is not a guarantee of atomic
filesystem writes if writing itself fails.

## Runtime DTO Validation

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
