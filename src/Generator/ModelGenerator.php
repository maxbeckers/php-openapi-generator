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

        $interfaceMap = [];
        $baseParentMap = [];
        foreach ($contexts as $ctx) {
            $sc = $ctx->schema;
            $schema = $components->schemas[$sc->schemaName] ?? null;
            if ($schema === null) {
                continue;
            }

            if ($sc->kind === SchemaKind::Interface) {
                $ifFqcn = $sc->namespace . '\\' . $sc->className;
                foreach (array_merge($schema->oneOf, $schema->anyOf) as $sub) {
                    if ($sub->ref !== null) {
                        $implName = $this->extractRefName($sub->ref);
                        $interfaceMap[$implName][] = $ifFqcn;
                    }
                }
                continue;
            }

            if ($sc->kind === SchemaKind::Object
                && $schema->discriminator !== null
                && (!empty($schema->oneOf) || !empty($schema->anyOf))
            ) {
                $baseFqcn = $sc->namespace . '\\' . $sc->className;
                foreach ($this->getDiscriminatorChildSchemaNames($schema) as $childSchemaName) {
                    $baseParentMap[$childSchemaName] = [
                        'fqcn' => $baseFqcn,
                        'props' => $sc->constructorProperties,
                    ];
                }
            }
        }
        foreach ($contexts as $ctx) {
            $sc = $ctx->schema;
            if (isset($interfaceMap[$sc->schemaName])) {
                foreach ($interfaceMap[$sc->schemaName] as $ifFqcn) {
                    if (!in_array($ifFqcn, $sc->implementsInterfaces, true)) {
                        $sc->implementsInterfaces[] = $ifFqcn;
                        $sc->imports->add($ifFqcn);
                    }
                }
            }

            if (isset($baseParentMap[$sc->schemaName]) && $sc->parentClass === null) {
                /** @var array{fqcn: string, props: array<int, PropertyContext>} $baseParent */
                $baseParent = $baseParentMap[$sc->schemaName];
                $sc->parentClass = $baseParent['fqcn'];
                $sc->imports->add($baseParent['fqcn']);
                $sc->parentConstructorProperties = $baseParent['props'];
                $sc->constructorProperties = $this->mergeUniqueProperties($baseParent['props'], $sc->properties);
            }
        }

        return $contexts;
    }

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

        $properties = [];
        if ($kind === SchemaKind::Object) {
            $properties = $this->resolveProperties($schema, $circularProps, $imports, $components);
        }

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
            } else {
                foreach (array_merge($schema->oneOf, $schema->anyOf) as $sub) {
                    if ($sub->ref !== null) {
                        $refName = $this->extractRefName($sub->ref);
                        $fqcn = $namespace . '\\' . $this->naming->className($refName);
                        $unionTypes[] = $imports->add($fqcn);
                    }
                }
            }
        }

        $discriminatorCases = $this->buildDiscriminatorCases($schemaName, $schema, $components, $imports);

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
            discriminatorCases: $discriminatorCases,
            implementsInterfaces: $implementsInterfaces,
            unionTypes: $unionTypes,
            imports: $imports,
        );

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
        Schema $schema,
        array $circularProps,
        ImportManager $imports,
        Components $components,
    ): array {
        $allProperties = [];
        $allRequired = $schema->required;

        $parentRefName = null;
        $parentPropertyNames = [];
        if (!empty($schema->allOf)) {
            $refs = array_filter($schema->allOf, fn ($s) => $s->ref !== null);
            if (count($refs) === 1) {
                $parentRefName = $this->extractRefName(reset($refs)->ref);
                $parentSchema = $components->schemas[$parentRefName] ?? null;
                if ($parentSchema !== null) {
                    [$parentProperties] = $this->flattenSchemaProperties($parentSchema, $components, [$parentRefName => true]);
                    $parentPropertyNames = array_keys($parentProperties);
                }
            }
        }

        foreach ($schema->allOf as $sub) {
            if ($sub->ref !== null) {
                $refName = $this->extractRefName($sub->ref);
                $refSchema = $components->schemas[$refName] ?? null;
                if ($refSchema !== null) {
                    [$refProperties, $refRequired] = $this->flattenSchemaProperties($refSchema, $components, [$refName => true]);
                    $allRequired = array_unique(array_merge($allRequired, $refRequired));
                    if ($refName === $parentRefName) {
                        continue;
                    }
                    foreach ($refProperties as $k => $v) {
                        $allProperties[$k] = $v;
                    }
                }
            } else {
                [$subProperties, $subRequired] = $this->flattenSchemaProperties($sub, $components);
                foreach ($subProperties as $k => $v) {
                    if (in_array($k, $parentPropertyNames, true)) {
                        continue;
                    }
                    $allProperties[$k] = $v;
                }
                $allRequired = array_unique(array_merge($allRequired, $subRequired));
            }
        }

        foreach ($schema->properties as $k => $v) {
            if (in_array($k, $parentPropertyNames, true)) {
                continue;
            }
            $allProperties[$k] = $v;
        }

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
     * Flatten direct and composed properties for a schema.
     *
     * @param array<string, bool> $visitedRefs
     *
     * @return array{0: array<string, Schema>, 1: string[]}
     */
    private function flattenSchemaProperties(
        Schema $schema,
        Components $components,
        array $visitedRefs = [],
    ): array {
        $properties = [];
        $required = $schema->required;

        foreach ($schema->allOf as $sub) {
            if ($sub->ref !== null) {
                $refName = $this->extractRefName($sub->ref);
                if ($refName === '' || isset($visitedRefs[$refName])) {
                    continue;
                }

                $refSchema = $components->schemas[$refName] ?? null;
                if ($refSchema === null) {
                    continue;
                }

                [$refProperties, $refRequired] = $this->flattenSchemaProperties(
                    $refSchema,
                    $components,
                    $visitedRefs + [$refName => true],
                );

                foreach ($refProperties as $name => $propSchema) {
                    $properties[$name] = $propSchema;
                }
                $required = array_unique(array_merge($required, $refRequired));
                continue;
            }

            [$subProperties, $subRequired] = $this->flattenSchemaProperties($sub, $components, $visitedRefs);
            foreach ($subProperties as $name => $propSchema) {
                $properties[$name] = $propSchema;
            }
            $required = array_unique(array_merge($required, $subRequired));
        }

        foreach ($schema->properties as $name => $propSchema) {
            $properties[$name] = $propSchema;
        }

        return [$properties, $required];
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

    /**
     * @return array<string, string> map discriminator value => generated class short name
     */
    private function buildDiscriminatorCases(
        string $schemaName,
        Schema $schema,
        Components $components,
        ImportManager $imports,
    ): array {
        if ($schema->discriminator === null || $schema->discriminator->propertyName === '') {
            return [];
        }

        $cases = [];

        // Prefer explicit discriminator mappings from the schema itself.
        foreach ($schema->discriminator->mapping as $discValue => $mapped) {
            $refName = $this->normalizeDiscriminatorTargetName($mapped);
            if ($refName === '' || !isset($components->schemas[$refName])) {
                continue;
            }
            $fqcn = $this->naming->modelNamespace() . '\\' . $this->naming->className($refName);
            $cases[(string) $discValue] = $imports->add($fqcn);
        }

        // If parent discriminator has no explicit mapping, infer from extending allOf schemas.
        if ($cases !== []) {
            return $cases;
        }

        $children = [];
        foreach ($components->schemas as $name => $candidate) {
            $candidateParent = $this->resolveParentRefName($candidate);
            if ($candidateParent === $schemaName) {
                $children[] = $name;
            }
        }

        foreach ($children as $childName) {
            $discValue = $this->inferDiscriminatorValue($schemaName, $childName);
            if ($discValue === '') {
                continue;
            }
            $fqcn = $this->naming->modelNamespace() . '\\' . $this->naming->className($childName);
            $cases[$discValue] = $imports->add($fqcn);
        }

        return $cases;
    }

    /**
     * @return string[]
     */
    private function getDiscriminatorChildSchemaNames(Schema $schema): array
    {
        $names = [];

        foreach ($schema->discriminator?->mapping ?? [] as $mapped) {
            $name = $this->normalizeDiscriminatorTargetName($mapped);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        if ($names !== []) {
            return array_values(array_unique($names));
        }

        foreach (array_merge($schema->oneOf, $schema->anyOf) as $sub) {
            if ($sub->ref !== null) {
                $name = $this->extractRefName($sub->ref);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    private function normalizeDiscriminatorTargetName(string $target): string
    {
        if (str_contains($target, '/')) {
            return $this->extractRefName($target);
        }

        return $target;
    }

    private function inferDiscriminatorValue(string $parentName, string $childName): string
    {
        $suffix = $this->extractTrailingWord($parentName);
        $base = $childName;

        if ($suffix !== '' && str_ends_with($childName, $suffix)) {
            $base = substr($childName, 0, -strlen($suffix));
        }

        if ($base === '') {
            return '';
        }

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $base));
    }

    private function extractTrailingWord(string $name): string
    {
        if (preg_match('/([A-Z][a-z0-9]*)$/', $name, $m) === 1) {
            return $m[1];
        }

        return '';
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
