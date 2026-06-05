# Extension Plugins (`x-*`)

The generator supports OpenAPI vendor extensions (`x-*`) through a plugin system.

Use plugins when you want custom generation behavior without forking templates or core logic.

## Built-In Plugins

| Plugin | Extension | Effect |
|---|---|---|
| `TrimPlugin` | `x-trim: <int>` | Truncates the property value via `substr()` in `fromArray()` |
| `SensitivePlugin` | `x-sensitive: true` | Adds `#[\SensitiveParameter]` to the constructor parameter (PHP 8.2+) |

Both plugins are registered automatically unless you opt out (see below).

## Example: `x-trim`

```yaml
components:
  schemas:
    CreatePet:
      type: object
      properties:
        name:
          type: string
          x-trim: 30
```

Generated `fromArray()` will truncate `name` to 30 characters.

## Example: `x-sensitive`

```yaml
components:
  schemas:
    Credentials:
      type: object
      properties:
        password:
          type: string
          x-sensitive: true
```

Generated constructor will mark `$password` with `#[\SensitiveParameter]`.

## Registering User Plugins

User plugins are registered directly on `GeneratorConfig` and are invoked **after** the built-in
plugins (in registration order):

```php
use MyApp\Plugin\MyCustomPlugin;

$config = new GeneratorConfig();
$config->addPropertyPlugin(new MyCustomPlugin());
```

Schema-level plugins (acting on the whole schema, not individual properties) use `addSchemaPlugin()`:

```php
$config->addSchemaPlugin(new MySchemaPlugin());
```

## Disabling Built-In Plugins

Set `disableBuiltinPlugins = true` to stop the built-ins from running:

```php
$config->disableBuiltinPlugins = true;
```

When disabled, **only** the user-registered plugins run.

## Plugin Execution Order

Plugins are executed in the following order:

1. Built-in plugins (`TrimPlugin`, then `SensitivePlugin`) — *skipped when `disableBuiltinPlugins = true`*
2. User plugins — in the order they were registered via `addPropertyPlugin()` / `addSchemaPlugin()`

If you need a user plugin to run **before** the built-ins, disable the built-ins and re-register
them manually after your plugin:

```php
use MaxBeckers\OpenApiGenerator\Plugin\Builtin\TrimPlugin;
use MaxBeckers\OpenApiGenerator\Plugin\Builtin\SensitivePlugin;

$config->disableBuiltinPlugins = true;
$config->addPropertyPlugin(new MyFirstPlugin());   // runs first
$config->addPropertyPlugin(new TrimPlugin());       // runs second
$config->addPropertyPlugin(new SensitivePlugin());  // runs third
```

## Writing a Custom Plugin

The extension system includes two interfaces:

- `PropertyExtensionPluginInterface` for property-level `x-*`
- `SchemaExtensionPluginInterface` for schema-level `x-*`

Typical use cases:

- add custom attributes
- append extra PHPDoc
- inject additional `fromArray()` lines
- add schema-level helper methods

See `src/Plugin/Extension/` for context/result objects and extension contracts.

### Property Plugin Example

```php
use MaxBeckers\OpenApiGenerator\Plugin\Extension\PropertyExtensionContext;
use MaxBeckers\OpenApiGenerator\Plugin\Extension\PropertyExtensionPluginInterface;
use MaxBeckers\OpenApiGenerator\Plugin\Extension\PropertyExtensionResult;

class MyObfuscatePlugin implements PropertyExtensionPluginInterface
{
    public function process(PropertyExtensionContext $context): ?PropertyExtensionResult
    {
        if (!($context->extensions['x-obfuscate'] ?? false)) {
            return null;
        }

        return new PropertyExtensionResult(
            extraAttributes: ['#[Obfuscate]'],
        );
    }
}
```

## Tips

- keep extension keys specific (e.g. `x-company-mask`)
- prefer deterministic transformations for repeatable generation
- document extension behavior in your API style guide
