<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Generator;

use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Generator\Context\GenerationContext;
use MaxBeckers\OpenApiGenerator\Generator\Context\PropertyContext;
use MaxBeckers\OpenApiGenerator\Generator\Context\SchemaContext;
use MaxBeckers\OpenApiGenerator\Plugin\Extension\PropertyExtensionContext;
use MaxBeckers\OpenApiGenerator\Plugin\Extension\PropertyExtensionPluginInterface;
use MaxBeckers\OpenApiGenerator\Plugin\Extension\SchemaExtensionContext;
use MaxBeckers\OpenApiGenerator\Plugin\Extension\SchemaExtensionPluginInterface;
use MaxBeckers\OpenApiGenerator\Spec\Components;
use MaxBeckers\OpenApiGenerator\Spec\Schema;

/**
 * Main generator: builds GenerationContext objects for each schema and
 * delegates rendering to the TemplateEngine.
 */
class ModelGenerator implements GeneratorInterface
{
    /** @var PropertyExtensionPluginInterface[] */
    private array $propertyPlugins = [];

    /** @var SchemaExtensionPluginInterface[] */
    private array $schemaPlugins = [];

    public function __construct(
        private readonly GeneratorConfig $config,
        private readonly NamingStrategy $naming,
        private readonly SchemaResolver $resolver,
        private readonly TemplateEngine $templateEngine,
    ) {
    }

    public function addPropertyPlugin(PropertyExtensionPluginInterface $plugin): void
    {
        $this->propertyPlugins[] = $plugin;
    }

    public function addSchemaPlugin(SchemaExtensionPluginInterface $plugin): void
    {
        $this->schemaPlugins[] = $plugin;
    }

    // -------------------------------------------------------------------------
    // GeneratorInterface
    // -------------------------------------------------------------------------

    public function canGenerate(GenerationContext $context): bool
    {
        return $context->schema->kind !== SchemaKind::Alias;
    }

    /**
     * @return array<string, string>
     */
    public function generate(GenerationContext $context): array
    {
        return match ($context->schema->kind) {
            SchemaKind::Object    => $this->renderClass($context),
            SchemaKind::Enum      => $this->renderEnum($context),
            SchemaKind::Interface => $this->renderInterface($context),
            SchemaKind::Alias     => [],
        };
    }

    public function getOutputPath(GenerationContext $context, string $filename): string
    {
        return $context->config->modelOutputDir . DIRECTORY_SEPARATOR . $filename;
    }

    // -------------------------------------------------------------------------
    // Context building (called by OpenApiService)
    // -------------------------------------------------------------------------

    /**
     * Build all GenerationContext objects for the given components.
     * Must be called after SchemaResolver::resolve().
     *
     * @param array<string, SchemaKind> $kinds
     *
     * @return GenerationContext[]
     */
    public function buildContexts(Components $components, array $kinds): array
    {
        $contexts = [];

        foreach ($kinds as $schemaName => $kind) {
            if ($kind === SchemaKind::Alias) {
                continue;
            }

            $schema = $components->schemas[$schemaName] ?? null;
            if ($schema === null) {
                continue;
            }

            $schemaContext = $this->buildSchemaContext($schemaName, $schema, $kind, $components);
            $contexts[] = new GenerationContext($this->config, $schemaContext);
        }

        // Second pass: for every discriminator interface, add it to each implementing
        // schema's implementsInterfaces so concrete classes get `implements XxxInterface`.
        $interfaceMap = [];  // implementingSchemaName => [interfaceFqcn, ...]
        foreach ($contexts as $ctx) {
            $sc = $ctx->schema;
            if ($sc->kind !== SchemaKind::Interface) {
                continue;
            }
            $schema = $components->schemas[$sc->schemaName] ?? null;
            if ($schema === null) {
                continue;
            }
            $ifFqcn = $sc->namespace . '\\' . $sc->className;
            foreach (array_merge($schema->oneOf, $schema->anyOf) as $sub) {
                if ($sub->ref !== null) {
                    $implName = $this->extractRefName($sub->ref);
                    $interfaceMap[$implName][] = $ifFqcn;
                }
            }
        }
        foreach ($contexts as $ctx) {
            $sc = $ctx->schema;
            if (!isset($interfaceMap[$sc->schemaName])) {
                continue;
            }
            foreach ($interfaceMap[$sc->schemaName] as $ifFqcn) {
                if (!in_array($ifFqcn, $sc->implementsInterfaces, true)) {
                    $sc->implementsInterfaces[] = $ifFqcn;
                    $sc->imports->add($ifFqcn);
                }
            }
        }

        return $contexts;
    }

    // -------------------------------------------------------------------------
    // Private: context building
    // -------------------------------------------------------------------------

    private function buildSchemaContext(
        string $schemaName,
        Schema $schema,
        SchemaKind $kind,
        Components $components,
    ): SchemaContext {
        $namespace = $this->naming->modelNamespace();
        $imports = new ImportManager($namespace);

        $className = match ($kind) {
            SchemaKind::Enum      => $this->naming->enumName($schemaName),
            SchemaKind::Interface => $this->naming->interfaceName($schemaName),
            default               => $this->naming->className($schemaName),
        };

        $circularProps = $this->resolver->getCircularProperties($schemaName);

        // Resolve properties for object schemas
        $properties = [];
        if ($kind === SchemaKind::Object) {
            $properties = $this->resolveProperties($schemaName, $schema, $circularProps, $imports, $components);
        }

        // Resolve parent class (allOf single $ref pattern)
        $parentClass = null;
        $parentConstructorProperties = [];
        $constructorProperties = $properties;
        $implementsInterfaces = [];
        $unionTypes = [];

        $parentRefName = $this->resolveParentRefName($schema);
        if ($parentRefName !== null) {
            $refName = $parentRefName;
            $parentClass = $namespace . '\\' . $this->naming->className($refName);
            $imports->add($parentClass);

            $parentSchema = $components->schemas[$refName] ?? null;
            if ($parentSchema !== null) {
                $parentConstructorProperties = $this->buildConstructorProperties(
                    schemaName: $refName,
                    schema: $parentSchema,
                    imports: $imports,
                    components: $components,
                );
                $constructorProperties = $this->mergeUniqueProperties($parentConstructorProperties, $properties);
            }
        }

        if (!empty($schema->oneOf) || !empty($schema->anyOf)) {
            if ($schema->discriminator !== null) {
                // This schema is the interface; implementing schemas are added in the
                // second pass inside buildContexts(). Nothing to do here.
            } else {
                // Union type members
                foreach (array_merge($schema->oneOf, $schema->anyOf) as $sub) {
                    if ($sub->ref !== null) {
                        $refName = $this->extractRefName($sub->ref);
                        $fqcn = $namespace . '\\' . $this->naming->className($refName);
                        $unionTypes[] = $imports->add($fqcn);
                    }
                }
            }
        }

        $ctx = new SchemaContext(
            schemaName: $schemaName,
            className: $className,
            namespace: $namespace,
            kind: $kind,
            schema: $schema,
            properties: $properties,
            circularProperties: $circularProps,
            parentClass: $parentClass,
            parentConstructorProperties: $parentConstructorProperties,
            constructorProperties: $constructorProperties,
            implementsInterfaces: $implementsInterfaces,
            unionTypes: $unionTypes,
            imports: $imports,
        );

        // Apply schema-level plugins
        foreach ($this->schemaPlugins as $plugin) {
            $plugin->process(new SchemaExtensionContext($ctx, $schema->extensions));
        }

        return $ctx;
    }

    /**
     * @param string[] $circularProps
     *
     * @return PropertyContext[]
     */
    private function resolveProperties(
        string $schemaName,
        Schema $schema,
        array $circularProps,
        ImportManager $imports,
        Components $components,
    ): array {
        // Collect merged properties from allOf first
        $allProperties = [];
        $allRequired = $schema->required;

        // Detect single-ref allOf → "extends" pattern; skip that ref's own properties
        // so the child class does not re-declare inherited readonly properties.
        $parentRefName = null;
        if (!empty($schema->allOf)) {
            $refs = array_filter($schema->allOf, fn ($s) => $s->ref !== null);
            if (count($refs) === 1) {
                $parentRefName = $this->extractRefName(reset($refs)->ref);
            }
        }

        foreach ($schema->allOf as $sub) {
            if ($sub->ref !== null) {
                $refName = $this->extractRefName($sub->ref);
                $refSchema = $components->schemas[$refName] ?? null;
                if ($refSchema !== null) {
                    // Always inherit required list so child knows which props are required.
                    $allRequired = array_unique(array_merge($allRequired, $refSchema->required));
                    // But skip property declarations when this IS the parent class —
                    // those properties are inherited and must not be re-declared.
                    if ($refName === $parentRefName) {
                        continue;
                    }
                    foreach ($refSchema->properties as $k => $v) {
                        $allProperties[$k] = $v;
                    }
                }
            } else {
                foreach ($sub->properties as $k => $v) {
                    $allProperties[$k] = $v;
                }
                $allRequired = array_unique(array_merge($allRequired, $sub->required));
            }
        }

        // Own properties override allOf properties
        foreach ($schema->properties as $k => $v) {
            $allProperties[$k] = $v;
        }

        // Sort: required first if configured
        if ($this->config->sortPropertiesByRequired) {
            uksort($allProperties, function (string $a, string $b) use ($allRequired): int {
                $aReq = in_array($a, $allRequired, true);
                $bReq = in_array($b, $allRequired, true);
                if ($aReq === $bReq) {
                    return 0;
                }

                return $aReq ? -1 : 1;
            });
        }

        $contexts = [];
        foreach ($allProperties as $wireName => $propSchema) {
            $phpName = $this->naming->propertyName($wireName);
            $required = in_array($wireName, $allRequired, true);
            $isCircular = in_array($wireName, $circularProps, true);

            $phpType = $this->resolvePhpType($propSchema, $imports, $components);
            if ($propSchema->nullable && !str_starts_with($phpType, '?')) {
                $phpType = '?' . $phpType;
            }
            // Non-required properties without an explicit default become nullable
            if (!$required && !$propSchema->hasDefault && !$propSchema->nullable
                && !str_starts_with($phpType, '?') && $phpType !== 'mixed' && !$isCircular
            ) {
                $phpType = '?' . $phpType;
            }

            $propCtx = new PropertyContext(
                wireName: $wireName,
                phpName: $phpName,
                phpType: $phpType,
                required: $required,
                nullable: $propSchema->nullable,
                readOnly: $propSchema->readOnly,
                writeOnly: $propSchema->writeOnly,
                default: $propSchema->default,
                hasDefault: $propSchema->hasDefault,
                isCircular: $isCircular,
                description: $propSchema->description,
                extensions: $propSchema->extensions,
                schema: $propSchema,
            );

            // Apply property-level plugins
            foreach ($this->propertyPlugins as $plugin) {
                $result = $plugin->process(new PropertyExtensionContext($propCtx, $propSchema->extensions));
                if ($result !== null) {
                    foreach ($result->extraAttributes as $attr) {
                        $propCtx->extraAttributes[] = $attr;
                    }
                    if ($result->extraCode !== null) {
                        $propCtx->extraCode = ($propCtx->extraCode !== null)
                            ? $propCtx->extraCode . "\n" . $result->extraCode
                            : $result->extraCode;
                    }
                }
            }

            $contexts[] = $propCtx;
        }

        return $contexts;
    }

    /**
     * Build constructor parameter list for a schema including inherited parent params.
     *
     * @param array<string, bool> $visited
     *
     * @return PropertyContext[]
     */
    private function buildConstructorProperties(
        string $schemaName,
        Schema $schema,
        ImportManager $imports,
        Components $components,
        array $visited = [],
    ): array {
        if (isset($visited[$schemaName])) {
            return [];
        }
        $visited[$schemaName] = true;

        $ownProperties = $this->resolveProperties(
            schemaName: $schemaName,
            schema: $schema,
            circularProps: $this->resolver->getCircularProperties($schemaName),
            imports: $imports,
            components: $components,
        );

        $parentRefName = $this->resolveParentRefName($schema);
        if ($parentRefName === null) {
            return $ownProperties;
        }

        $parentSchema = $components->schemas[$parentRefName] ?? null;
        if ($parentSchema === null) {
            return $ownProperties;
        }

        $parentProperties = $this->buildConstructorProperties(
            schemaName: $parentRefName,
            schema: $parentSchema,
            imports: $imports,
            components: $components,
            visited: $visited,
        );

        return $this->mergeUniqueProperties($parentProperties, $ownProperties);
    }

    private function resolveParentRefName(Schema $schema): ?string
    {
        if (empty($schema->allOf)) {
            return null;
        }

        $refs = array_filter($schema->allOf, fn ($s) => $s->ref !== null);
        if (count($refs) !== 1) {
            return null;
        }

        return $this->extractRefName(reset($refs)->ref);
    }

    /**
     * @param PropertyContext[] $base
     * @param PropertyContext[] $extra
     *
     * @return PropertyContext[]
     */
    private function mergeUniqueProperties(array $base, array $extra): array
    {
        $merged = [];
        $seen = [];

        foreach (array_merge($base, $extra) as $property) {
            if (isset($seen[$property->phpName])) {
                continue;
            }
            $seen[$property->phpName] = true;
            $merged[] = $property;
        }

        return $merged;
    }

    private function resolvePhpType(Schema $propSchema, ImportManager $imports, Components $components): string
    {
        // $ref
        if ($propSchema->ref !== null) {
            $refName = $this->extractRefName($propSchema->ref);
            $kind = $this->resolver->getKind($refName);
            $fqcn = $this->naming->modelNamespace() . '\\' . match ($kind) {
                SchemaKind::Enum      => $this->naming->enumName($refName),
                SchemaKind::Interface => $this->naming->interfaceName($refName),
                default               => $this->naming->className($refName),
            };

            return $imports->add($fqcn);
        }

        // array
        if ($propSchema->type === 'array') {
            if ($propSchema->items !== null) {
                $itemType = $this->resolvePhpType($propSchema->items, $imports, $components);

                return $itemType . '[]';
            }

            return 'array';
        }

        // object (inline — should have been hoisted, but fallback)
        if ($propSchema->type === 'object' || !empty($propSchema->properties)) {
            return 'array';
        }

        // Check custom type mapping
        if ($propSchema->format !== null && isset($this->config->typeMapping[$propSchema->format])) {
            return $this->config->typeMapping[$propSchema->format];
        }

        // Scalar mapping
        return match ($propSchema->type) {
            'integer'       => 'int',
            'number'        => 'float',
            'boolean'       => 'bool',
            'string'        => $this->resolveStringType($propSchema),
            default         => 'mixed',
        };
    }

    private function resolveStringType(Schema $schema): string
    {
        return match ($schema->format) {
            'date-time', 'date-time-only' => $this->config->dateTimeClass,
            'date'                         => $this->config->dateClass,
            default                        => 'string',
        };
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /** @return array<string, string> */
    private function renderClass(GenerationContext $context): array
    {
        $sc = $context->schema;
        $content = $this->templateEngine->render('model/class.php.twig', [
            'config'     => $context->config,
            'schema'     => $sc,
            'useStatements' => $sc->imports->getUseStatements(),
        ]);

        $file = $this->getOutputPath($context, $sc->className . '.php');

        return [$file => $content];
    }

    /** @return array<string, string> */
    private function renderEnum(GenerationContext $context): array
    {
        $sc = $context->schema;
        $isIntEnum = !empty($sc->schema->enum) && is_int($sc->schema->enum[0]);

        // Pre-process enum cases so the template gets [{caseName, value}] pairs.
        $enumCases = array_map(
            fn ($v) => ['caseName' => $this->naming->enumCaseName((string) $v), 'value' => $v],
            $sc->schema->enum,
        );

        $content = $this->templateEngine->render('model/enum.php.twig', [
            'config'     => $context->config,
            'schema'     => $sc,
            'useStatements' => $sc->imports->getUseStatements(),
            'isIntEnum'  => $isIntEnum,
            'enumCases'  => $enumCases,
        ]);

        $file = $this->getOutputPath($context, $sc->className . '.php');

        return [$file => $content];
    }

    /** @return array<string, string> */
    private function renderInterface(GenerationContext $context): array
    {
        $sc = $context->schema;
        $content = $this->templateEngine->render('model/interface.php.twig', [
            'config'     => $context->config,
            'schema'     => $sc,
            'useStatements' => $sc->imports->getUseStatements(),
        ]);

        $file = $this->getOutputPath($context, $sc->className . '.php');

        return [$file => $content];
    }

    private function extractRefName(string $ref): string
    {
        return (string) (array_reverse(explode('/', $ref))[0] ?? '');
    }
}
