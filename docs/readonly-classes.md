# Readonly Model Classes

Generated DTOs target PHP 8.2+ and can use `readonly` for immutability.

## Behavior

When `$config->phpReadonly = true` and constructor generation is enabled, model templates emit class-level readonly DTOs where possible.

Example:

```php
readonly class Pet
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $tag = null,
    ) {
    }
}
```

## Why This Helps

- prevents accidental state mutation after construction
- keeps generated DTOs predictable in request/response pipelines
- improves confidence when DTOs are passed across layers

## Notes

- Readonly only applies to generated model state, not to arrays returned by `toArray()`.
- If your project requires mutable models, set `$config->phpReadonly = false`.
