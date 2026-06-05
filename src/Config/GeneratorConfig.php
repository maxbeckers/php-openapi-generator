<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Config;

use MaxBeckers\OpenApiGenerator\Plugin\Extension\PropertyExtensionPluginInterface;
use MaxBeckers\OpenApiGenerator\Plugin\Extension\SchemaExtensionPluginInterface;

/**
 * Configuration for the OpenAPI code generator.
 *
 * All setters return $this for fluent chaining.
 */
class GeneratorConfig
{
    /** Directory where generated files are written (absolute or relative to config file). */
    public string $outputDir = 'generated';

    /** PHP version for which to generate code (e.g. '8.2'). */
    public string $phpVersion = '8.2';

    /** Root namespace for generated model classes. */
    public string $modelNamespace = 'Generated\\Model';

    /** Output sub-directory for model classes, relative to $outputDir. */
    public string $modelOutputDir = 'Model';

    /** Suffix appended to generated class names (e.g. 'Dto'). */
    public string $classSuffix = '';

    /** Suffix appended to generated interface names. */
    public string $interfaceSuffix = 'Interface';

    /** Suffix appended to generated enum names. */
    public string $enumSuffix = '';

    /** Prefix prepended to all generated class/interface/enum names. */
    public string $classPrefix = '';

    /** Convention used when naming PHP properties. */
    public PropertyNaming $propertyNaming = PropertyNaming::CamelCase;

    /** When a generated name conflicts with a PHP reserved word, append this suffix. */
    public string $reservedWordSuffix = '_';

    // -------------------------------------------------------------------------
    // Class generation options
    // -------------------------------------------------------------------------

    /** Whether to generate a typed constructor (constructor promotion). */
    public bool $generateConstructor = true;

    /** Whether to mark constructor-promoted properties as readonly. */
    public bool $phpReadonly = true;

    /** Whether to generate PHPDoc blocks on properties and methods. */
    public bool $generatePhpDoc = true;

    /** Whether to generate a static fromArray() factory method on DTOs. */
    public bool $generateFromArray = true;

    /** Whether to generate a toArray() method on DTOs. */
    public bool $generateToArray = true;

    /** Whether toArray() omits null values from the output. */
    public bool $omitNullsInToArray = true;

    /** Fully-qualified class to use for date-time properties (must implement DateTimeInterface). */
    public string $dateTimeClass = \DateTimeImmutable::class;

    /** Fully-qualified class to use for date-only properties. */
    public string $dateClass = \DateTimeImmutable::class;

    /**
     * Custom type mappings: maps OAS format strings to PHP type strings.
     * E.g. ['uuid' => 'string', 'binary' => 'string'].
     *
     * @var array<string, string>
     */
    public array $typeMapping = [];

    // -------------------------------------------------------------------------
    // Split read/write DTOs
    // -------------------------------------------------------------------------

    /**
     * When true, properties with readOnly:true are excluded from request DTOs
     * and properties with writeOnly:true are excluded from response DTOs.
     */
    public bool $splitReadWriteDtos = false;

    // -------------------------------------------------------------------------
    // Enum options
    // -------------------------------------------------------------------------

    /** Behaviour when an unknown enum value is encountered in fromArray(). */
    public EnumUnknownDefault $enumUnknownDefault = EnumUnknownDefault::Null;

    // -------------------------------------------------------------------------
    // Property ordering
    // -------------------------------------------------------------------------

    /** Whether to sort properties so that required ones appear before optional ones. */
    public bool $sortPropertiesByRequired = true;

    // -------------------------------------------------------------------------
    // Plugins
    // -------------------------------------------------------------------------

    /**
     * Whether to enable the built-in composer plugin that runs generation on
     * POST_AUTOLOAD_DUMP.
     */
    public bool $addPlugin = true;

    /** Whether to emit warnings when a plugin does not handle an extension. */
    public bool $verbosePluginWarnings = false;

    /**
     * When true, the built-in TrimPlugin and SensitivePlugin are NOT registered
     * automatically.  Use this when you want full control over which plugins
     * run and in what order.
     */
    public bool $disableBuiltinPlugins = false;

    /**
     * User-registered property-extension plugins.
     * They are applied after the built-in plugins (unless $disableBuiltinPlugins
     * is true, in which case only these plugins run in the order registered).
     *
     * @var PropertyExtensionPluginInterface[]
     */
    private array $propertyPlugins = [];

    /**
     * User-registered schema-extension plugins.
     * They are applied after the built-in plugins (unless $disableBuiltinPlugins
     * is true, in which case only these plugins run in the order registered).
     *
     * @var SchemaExtensionPluginInterface[]
     */
    private array $schemaPlugins = [];

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /** Strategy for generating validation code. */
    public ValidationStrategy $validationStrategy = ValidationStrategy::None;

    /** When true, validate() is called on server-side request parameters. */
    public bool $validateServerRequest = false;

    /** When true, validate() is called on client-side request parameters. */
    public bool $validateClientRequest = false;

    /** When true, validate() is called on client-side responses. */
    public bool $validateClientResponse = false;

    /** When true, validate() is called on server-side responses. */
    public bool $validateServerResponse = false;

    // -------------------------------------------------------------------------
    // Spec / auto-generation
    // -------------------------------------------------------------------------

    /** Path to the OpenAPI spec file (absolute or relative to config file). */
    public string $specFile = 'openapi.yaml';

    /** Whether to run generation automatically on composer post-autoload-dump. */
    public bool $autoGenerate = true;

    // -------------------------------------------------------------------------
    // Filtering
    // -------------------------------------------------------------------------

    /**
     * Only generate models used by operations with these tags.
     * Empty = include all.
     *
     * @var string[]
     */
    public array $includeTags = [];

    /**
     * Exclude operations with these tags (applied after $includeTags).
     *
     * @var string[]
     */
    public array $excludeTags = [];

    /**
     * Only generate models used by these operationIds.
     * Empty = include all.
     *
     * @var string[]
     */
    public array $includeOperationIds = [];

    /**
     * Exclude these operationIds.
     *
     * @var string[]
     */
    public array $excludeOperationIds = [];

    /**
     * Only generate models used by paths matching these patterns (fnmatch-style).
     * Empty = include all.
     *
     * @var string[]
     */
    public array $includePaths = [];

    /**
     * Exclude paths matching these patterns (applied after $includePaths).
     *
     * @var string[]
     */
    public array $excludePaths = [];

    /** What to generate: model-only, server-side stubs, or client-side HTTP service. */
    public GenerationTarget $generationTarget = GenerationTarget::Server;

    /** HTTP client adapter to use when generationTarget is Client. */
    public HttpClientAdapter $httpClient = HttpClientAdapter::SymfonyHttpClient;

    /** Framework integration when generationTarget is Server. */
    public FrameworkTarget $frameworkTarget = FrameworkTarget::None;

    /** Version of the framework (e.g. '8.0' for Symfony, '11.0' for Laravel). Used for version-specific code generation. */
    public ?string $frameworkVersion = null;

    /** HTTP client library version for client code generation (e.g. '7.0' for Guzzle). Not used for template logic currently. */
    public ?string $httpClientVersion = null;

    /** Root namespace for generated API classes (server/client). */
    public string $apiNamespace = '';

    /** Output sub-directory for API classes, relative to $outputDir. */
    public string $apiOutputDir = '';

    /** Whether to generate typed exception classes per error status code. */
    public bool $typedErrorResponses = false;

    /** Whether to inject security scheme authentication into generated clients. */
    public bool $generateSecuritySchemes = true;

    public function setOutputDir(string $outputDir): static
    {
        $this->outputDir = $outputDir;

        return $this;
    }

    public function setPhpVersion(string $phpVersion): static
    {
        $this->phpVersion = $phpVersion;

        return $this;
    }

    public function setModelNamespace(string $modelNamespace): static
    {
        $this->modelNamespace = $modelNamespace;

        return $this;
    }

    public function setModelOutputDir(string $modelOutputDir): static
    {
        $this->modelOutputDir = $modelOutputDir;

        return $this;
    }

    public function setClassSuffix(string $classSuffix): static
    {
        $this->classSuffix = $classSuffix;

        return $this;
    }

    public function setInterfaceSuffix(string $interfaceSuffix): static
    {
        $this->interfaceSuffix = $interfaceSuffix;

        return $this;
    }

    public function setEnumSuffix(string $enumSuffix): static
    {
        $this->enumSuffix = $enumSuffix;

        return $this;
    }

    public function setClassPrefix(string $classPrefix): static
    {
        $this->classPrefix = $classPrefix;

        return $this;
    }

    public function setPropertyNaming(PropertyNaming $propertyNaming): static
    {
        $this->propertyNaming = $propertyNaming;

        return $this;
    }

    public function setReservedWordSuffix(string $reservedWordSuffix): static
    {
        $this->reservedWordSuffix = $reservedWordSuffix;

        return $this;
    }

    public function setGenerateConstructor(bool $generateConstructor): static
    {
        $this->generateConstructor = $generateConstructor;

        return $this;
    }

    public function setPhpReadonly(bool $phpReadonly): static
    {
        $this->phpReadonly = $phpReadonly;

        return $this;
    }

    public function setGeneratePhpDoc(bool $generatePhpDoc): static
    {
        $this->generatePhpDoc = $generatePhpDoc;

        return $this;
    }

    public function setGenerateFromArray(bool $generateFromArray): static
    {
        $this->generateFromArray = $generateFromArray;

        return $this;
    }

    public function setGenerateToArray(bool $generateToArray): static
    {
        $this->generateToArray = $generateToArray;

        return $this;
    }

    public function setOmitNullsInToArray(bool $omitNullsInToArray): static
    {
        $this->omitNullsInToArray = $omitNullsInToArray;

        return $this;
    }

    public function setDateTimeClass(string $dateTimeClass): static
    {
        $this->dateTimeClass = $dateTimeClass;

        return $this;
    }

    public function setDateClass(string $dateClass): static
    {
        $this->dateClass = $dateClass;

        return $this;
    }

    /** @param array<string, string> $typeMapping */
    public function setTypeMapping(array $typeMapping): static
    {
        $this->typeMapping = $typeMapping;

        return $this;
    }

    public function setSplitReadWriteDtos(bool $splitReadWriteDtos): static
    {
        $this->splitReadWriteDtos = $splitReadWriteDtos;

        return $this;
    }

    public function setEnumUnknownDefault(EnumUnknownDefault $enumUnknownDefault): static
    {
        $this->enumUnknownDefault = $enumUnknownDefault;

        return $this;
    }

    public function setSortPropertiesByRequired(bool $sortPropertiesByRequired): static
    {
        $this->sortPropertiesByRequired = $sortPropertiesByRequired;

        return $this;
    }

    public function setAddPlugin(bool $addPlugin): static
    {
        $this->addPlugin = $addPlugin;

        return $this;
    }

    public function setVerbosePluginWarnings(bool $verbosePluginWarnings): static
    {
        $this->verbosePluginWarnings = $verbosePluginWarnings;

        return $this;
    }

    public function setDisableBuiltinPlugins(bool $disableBuiltinPlugins): static
    {
        $this->disableBuiltinPlugins = $disableBuiltinPlugins;

        return $this;
    }

    /**
     * Register a user property-extension plugin.
     *
     * Plugins are invoked in registration order.  When $disableBuiltinPlugins
     * is false (the default), built-in plugins run before user plugins.  Set
     * $disableBuiltinPlugins = true and re-register the built-ins yourself if
     * you need a different order.
     */
    public function addPropertyPlugin(PropertyExtensionPluginInterface $plugin): static
    {
        $this->propertyPlugins[] = $plugin;

        return $this;
    }

    /**
     * Register a user schema-extension plugin.
     *
     * @see addPropertyPlugin() for ordering notes
     */
    public function addSchemaPlugin(SchemaExtensionPluginInterface $plugin): static
    {
        $this->schemaPlugins[] = $plugin;

        return $this;
    }

    /** @return PropertyExtensionPluginInterface[] */
    public function getPropertyPlugins(): array
    {
        return $this->propertyPlugins;
    }

    /** @return SchemaExtensionPluginInterface[] */
    public function getSchemaPlugins(): array
    {
        return $this->schemaPlugins;
    }

    public function setValidationStrategy(ValidationStrategy $validationStrategy): static
    {
        $this->validationStrategy = $validationStrategy;

        return $this;
    }

    public function setValidateServerRequest(bool $validateServerRequest): static
    {
        $this->validateServerRequest = $validateServerRequest;

        return $this;
    }

    public function setValidateClientRequest(bool $validateClientRequest): static
    {
        $this->validateClientRequest = $validateClientRequest;

        return $this;
    }

    public function setValidateClientResponse(bool $validateClientResponse): static
    {
        $this->validateClientResponse = $validateClientResponse;

        return $this;
    }

    public function setValidateServerResponse(bool $validateServerResponse): static
    {
        $this->validateServerResponse = $validateServerResponse;

        return $this;
    }

    public function setSpecFile(string $specFile): static
    {
        $this->specFile = $specFile;

        return $this;
    }

    public function setAutoGenerate(bool $autoGenerate): static
    {
        $this->autoGenerate = $autoGenerate;

        return $this;
    }

    /** @param string[] $includeTags */
    public function setIncludeTags(array $includeTags): static
    {
        $this->includeTags = $includeTags;

        return $this;
    }

    /** @param string[] $excludeTags */
    public function setExcludeTags(array $excludeTags): static
    {
        $this->excludeTags = $excludeTags;

        return $this;
    }

    /** @param string[] $includeOperationIds */
    public function setIncludeOperationIds(array $includeOperationIds): static
    {
        $this->includeOperationIds = $includeOperationIds;

        return $this;
    }

    /** @param string[] $excludeOperationIds */
    public function setExcludeOperationIds(array $excludeOperationIds): static
    {
        $this->excludeOperationIds = $excludeOperationIds;

        return $this;
    }

    /** @param string[] $includePaths */
    public function setIncludePaths(array $includePaths): static
    {
        $this->includePaths = $includePaths;

        return $this;
    }

    /** @param string[] $excludePaths */
    public function setExcludePaths(array $excludePaths): static
    {
        $this->excludePaths = $excludePaths;

        return $this;
    }

    public function setGenerationTarget(GenerationTarget $generationTarget): static
    {
        $this->generationTarget = $generationTarget;

        return $this;
    }

    public function setHttpClient(HttpClientAdapter $httpClient): static
    {
        $this->httpClient = $httpClient;

        return $this;
    }

    public function setFrameworkTarget(FrameworkTarget $frameworkTarget): static
    {
        $this->frameworkTarget = $frameworkTarget;

        return $this;
    }

    public function setFrameworkVersion(?string $frameworkVersion): static
    {
        $this->frameworkVersion = $frameworkVersion;

        return $this;
    }

    public function setHttpClientVersion(?string $httpClientVersion): static
    {
        $this->httpClientVersion = $httpClientVersion;

        return $this;
    }

    public function setApiNamespace(string $apiNamespace): static
    {
        $this->apiNamespace = $apiNamespace;

        return $this;
    }

    public function setApiOutputDir(string $apiOutputDir): static
    {
        $this->apiOutputDir = $apiOutputDir;

        return $this;
    }

    public function setTypedErrorResponses(bool $typedErrorResponses): static
    {
        $this->typedErrorResponses = $typedErrorResponses;

        return $this;
    }

    public function setGenerateSecuritySchemes(bool $generateSecuritySchemes): static
    {
        $this->generateSecuritySchemes = $generateSecuritySchemes;

        return $this;
    }
}
