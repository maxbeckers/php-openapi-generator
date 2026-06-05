<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Integration;

use MaxBeckers\OpenApiGenerator\Config\EnumUnknownDefault;
use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Config\PropertyNaming;
use MaxBeckers\OpenApiGenerator\FileWriter\FileWriter;
use MaxBeckers\OpenApiGenerator\Generator\ModelGenerator;
use MaxBeckers\OpenApiGenerator\Generator\NamingStrategy;
use MaxBeckers\OpenApiGenerator\Generator\SchemaResolver;
use MaxBeckers\OpenApiGenerator\Generator\TemplateEngine;
use MaxBeckers\OpenApiGenerator\Loader\OpenApiLoader;
use MaxBeckers\OpenApiGenerator\Plugin\Extension\PropertyExtensionContext;
use MaxBeckers\OpenApiGenerator\Plugin\Extension\PropertyExtensionPluginInterface;
use MaxBeckers\OpenApiGenerator\Plugin\Extension\PropertyExtensionResult;
use MaxBeckers\OpenApiGenerator\Service\OpenApiService;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests covering generated code features:
 * - Nested object toArray() / fromArray() correctness
 * - @deprecated PHPDoc on schemas and properties
 * - enumUnknownDefault (Null / Throw / Raw)
 * - Integer-backed enums
 * - propertyNaming SnakeCase / Original
 * - typeMapping / dateTimeClass
 * - omitNullsInToArray
 * - Operation filter schema-reachability cascade
 */
class GeneratorFeaturesTest extends TestCase
{
    private string $outputDir;
    private OpenApiService $service;
    private static OpenApiService $sharedService;

    public static function setUpBeforeClass(): void
    {
        self::$sharedService = new OpenApiService(new OpenApiLoader(), new FileWriter());
    }

    protected function setUp(): void
    {
        $this->outputDir = sys_get_temp_dir() . '/openapi-feat-test-' . uniqid('', true);
        $this->service = self::$sharedService;
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->outputDir);
    }

    public function testNestedObjectToArrayCallsToArrayOnProperty(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $orderContent = file_get_contents($this->outputDir . '/Model/Order.php');
        self::assertNotFalse($orderContent);

        // Required non-nullable Money property — toArray() must call ->toArray()
        self::assertStringContainsString(
            "\$data['total'] = \$this->total->toArray();",
            $orderContent,
            'Required non-nullable nested object must call ->toArray()',
        );

        // Enum ref property — toArray() must call ->toArray()
        self::assertStringContainsString(
            "\$data['status'] = \$this->status->toArray();",
            $orderContent,
            'Enum property in toArray() must call ->toArray()',
        );
    }

    public function testNullableNestedObjectToArrayWithOmitNulls(): void
    {
        $config = $this->makeConfig();
        $config->omitNullsInToArray = true;
        $this->service->generate($config, self::FIXTURES_DIR);

        $orderContent = file_get_contents($this->outputDir . '/Model/Order.php');
        self::assertNotFalse($orderContent);

        // Optional nullable Address — omitNulls=true → if-block with ->toArray()
        self::assertStringContainsString(
            'if ($this->shippingAddress !== null) {',
            $orderContent,
        );
        self::assertStringContainsString(
            "\$data['shippingAddress'] = \$this->shippingAddress->toArray();",
            $orderContent,
        );
    }

    public function testNullableNestedObjectToArrayWithoutOmitNulls(): void
    {
        $config = $this->makeConfig();
        $config->omitNullsInToArray = false;
        $this->service->generate($config, self::FIXTURES_DIR);

        $orderContent = file_get_contents($this->outputDir . '/Model/Order.php');
        self::assertNotFalse($orderContent);

        // omitNulls=false → nullable object uses null-safe operator
        self::assertStringContainsString(
            "\$data['shippingAddress'] = \$this->shippingAddress?->toArray();",
            $orderContent,
            'Nullable nested object without omitNulls must use ?->toArray()',
        );
    }

    public function testNestedObjectFromArrayCallsFromArray(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $orderContent = file_get_contents($this->outputDir . '/Model/Order.php');
        self::assertNotFalse($orderContent);

        // Required non-nullable Money property — fromArray() must call Money::fromArray()
        self::assertStringContainsString(
            "Money::fromArray(\$data['total']",
            $orderContent,
            'Required non-nullable nested object must call ::fromArray()',
        );

        // Optional nullable Address property — fromArray() must use conditional
        self::assertStringContainsString(
            "Address::fromArray(\$data['shippingAddress']",
            $orderContent,
            'Nullable nested object must call ::fromArray() conditionally',
        );
    }

    public function testNestedObjectEnumRefUsesToArrayValue(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $orderContent = file_get_contents($this->outputDir . '/Model/Order.php');
        self::assertNotFalse($orderContent);

        // Enum property: toArray() must call ->toArray() (which returns ->value for enums)
        self::assertStringContainsString(
            "\$data['status'] = \$this->status->toArray();",
            $orderContent,
            'Enum property in toArray() must call ->toArray()',
        );
    }

    public function testDeprecatedSchemaEmitsPhpDocAnnotation(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = file_get_contents($this->outputDir . '/Model/DeprecatedSchema.php');
        self::assertNotFalse($content);

        self::assertStringContainsString(
            '@deprecated',
            $content,
            'Deprecated schema must emit @deprecated PHPDoc',
        );
    }

    public function testDeprecatedPropertyEmitsPhpDocAnnotation(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = file_get_contents($this->outputDir . '/Model/Address.php');
        self::assertNotFalse($content);

        // The deprecated 'oldZip' property must have @deprecated PHPDoc
        self::assertStringContainsString(
            '@deprecated',
            $content,
            'Deprecated property must emit @deprecated PHPDoc',
        );
    }

    public function testNonDeprecatedSchemaHasNoDeprecatedAnnotation(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = file_get_contents($this->outputDir . '/Model/Money.php');
        self::assertNotFalse($content);

        self::assertStringNotContainsString('@deprecated', $content);
    }

    public function testEnumDefaultNullUsesTryFrom(): void
    {
        $config = $this->makeConfig();
        $config->enumUnknownDefault = EnumUnknownDefault::Null;
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = file_get_contents($this->outputDir . '/Model/OrderStatus.php');
        self::assertNotFalse($content);

        self::assertStringContainsString('tryFrom(', $content);
        self::assertStringNotContainsString('::from(', $content);
        self::assertStringContainsString('?OrderStatus', $content);
    }

    public function testEnumThrowUsesFrom(): void
    {
        $config = $this->makeConfig();
        $config->enumUnknownDefault = EnumUnknownDefault::Throw;
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = file_get_contents($this->outputDir . '/Model/OrderStatus.php');
        self::assertNotFalse($content);

        self::assertStringContainsString('OrderStatus::from(', $content);
        self::assertStringNotContainsString('tryFrom(', $content);
        // Return type is non-nullable
        self::assertStringContainsString('): OrderStatus', $content);
    }

    public function testEnumRawUsesTryFromWithFallback(): void
    {
        $config = $this->makeConfig();
        $config->enumUnknownDefault = EnumUnknownDefault::Raw;
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = file_get_contents($this->outputDir . '/Model/OrderStatus.php');
        self::assertNotFalse($content);

        self::assertStringContainsString('tryFrom(', $content);
        // Raw mode falls back to first case
        self::assertStringContainsString('cases()[0]', $content);
    }

    public function testIntegerBackedEnum(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = file_get_contents($this->outputDir . '/Model/Priority.php');
        self::assertNotFalse($content);

        self::assertStringContainsString('enum Priority: int', $content);
        self::assertStringContainsString('case Value1 = 1;', $content);
        self::assertStringContainsString('case Value2 = 2;', $content);
        self::assertStringContainsString('(int) $value', $content);
    }

    public function testStringBackedEnumStillUsesString(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = file_get_contents($this->outputDir . '/Model/OrderStatus.php');
        self::assertNotFalse($content);

        self::assertStringContainsString('enum OrderStatus: string', $content);
        self::assertStringContainsString('(string) $value', $content);
    }

    public function testPropertyNamingCamelCaseIsDefault(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        // Money has 'amount' (already camelCase) and 'currency'
        $content = file_get_contents($this->outputDir . '/Model/Money.php');
        self::assertNotFalse($content);

        self::assertStringContainsString('$amount', $content);
        self::assertStringContainsString('$currency', $content);
    }

    public function testPropertyNamingSnakeCase(): void
    {
        // Address has 'postalCode' which would become 'postal_code' in snake_case
        // and 'shippingAddress' on Order becomes 'shipping_address'
        $config = $this->makeConfig();
        $config->propertyNaming = PropertyNaming::SnakeCase;
        $this->service->generate($config, self::FIXTURES_DIR);

        $orderContent = file_get_contents($this->outputDir . '/Model/Order.php');
        self::assertNotFalse($orderContent);

        // PHP property uses snake_case
        self::assertStringContainsString('$shipping_address', $orderContent);
        // Wire key in fromArray/toArray stays as original camelCase
        self::assertStringContainsString("'shippingAddress'", $orderContent);
    }

    public function testPropertyNamingOriginalKeepsWireName(): void
    {
        $config = $this->makeConfig();
        $config->propertyNaming = PropertyNaming::Original;
        $this->service->generate($config, self::FIXTURES_DIR);

        $orderContent = file_get_contents($this->outputDir . '/Model/Order.php');
        self::assertNotFalse($orderContent);

        // Property name equals wire name
        self::assertStringContainsString('$shippingAddress', $orderContent);
        self::assertStringContainsString("'shippingAddress'", $orderContent);
    }

    public function testDateTimeClassIsApplied(): void
    {
        $config = $this->makeConfig();
        $config->dateTimeClass = '\DateTimeImmutable';

        // Add a schema with date-time format via inline load
        $loader = new OpenApiLoader();
        $spec = $loader->load([
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1'],
            'paths' => [],
            'components' => [
                'schemas' => [
                    'Event' => [
                        'type' => 'object',
                        'required' => ['happenedAt'],
                        'properties' => [
                            'happenedAt' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ],
                ],
            ],
        ]);

        // Generate using service directly
        $service = new OpenApiService($loader, new FileWriter());
        $config->specFile = 'FAKE';

        // Use ModelGenerator directly to test type resolution
        $naming = new NamingStrategy($config);
        $resolver = new SchemaResolver($naming);
        $templatePath = realpath(__DIR__ . '/../../templates');
        $engine = new TemplateEngine($templatePath, '8.2');
        $generator = new ModelGenerator($config, $naming, $resolver, $engine);

        $kinds = $resolver->resolve($spec->components);
        $contexts = $generator->buildContexts($spec->components, $kinds);
        $files = [];
        foreach ($contexts as $ctx) {
            if ($generator->canGenerate($ctx)) {
                foreach ($generator->generate($ctx) as $path => $content) {
                    $files[$path] = $content;
                }
            }
        }

        $eventContent = $files['Model' . DIRECTORY_SEPARATOR . 'Event.php'] ?? null;
        self::assertNotNull($eventContent, 'Event.php was not generated');
        self::assertStringContainsString('DateTimeImmutable', $eventContent);
    }

    public function testTypeMappingOverridesFormat(): void
    {
        $config = $this->makeConfig();
        $config->typeMapping = ['uuid' => 'string'];

        // The petstore has a uuid format on Order.id — verify it uses 'string'
        // (already the default for uuid, but this confirms typeMapping is used)
        $loader = new OpenApiLoader();
        $spec = $loader->load([
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1'],
            'paths' => [],
            'components' => [
                'schemas' => [
                    'Resource' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid'],
                        ],
                    ],
                ],
            ],
        ]);

        $naming = new NamingStrategy($config);
        $resolver = new SchemaResolver($naming);
        $templatePath = realpath(__DIR__ . '/../../templates');
        $engine = new TemplateEngine($templatePath, '8.2');
        $generator = new ModelGenerator($config, $naming, $resolver, $engine);

        $kinds = $resolver->resolve($spec->components);
        $contexts = $generator->buildContexts($spec->components, $kinds);

        $idProp = null;
        foreach ($contexts as $ctx) {
            foreach ($ctx->schema->properties as $prop) {
                if ($prop->wireName === 'id') {
                    $idProp = $prop;
                }
            }
        }

        self::assertNotNull($idProp);
        self::assertSame('string', $idProp->phpType);
    }

    public function testOmitNullsInToArrayWrapsNullableScalar(): void
    {
        $config = $this->makeConfig();
        $config->omitNullsInToArray = true;
        $this->service->generate($config, self::FIXTURES_DIR);

        $orderContent = file_get_contents($this->outputDir . '/Model/Order.php');
        self::assertNotFalse($orderContent);

        // 'notes' is nullable string → must be wrapped in if-block
        self::assertStringContainsString(
            'if ($this->notes !== null) {',
            $orderContent,
        );
    }

    public function testOmitNullsInToArrayFalseEmitsNullDirectly(): void
    {
        $config = $this->makeConfig();
        $config->omitNullsInToArray = false;
        $this->service->generate($config, self::FIXTURES_DIR);

        $orderContent = file_get_contents($this->outputDir . '/Model/Order.php');
        self::assertNotFalse($orderContent);

        // Without omitNulls, nullable scalar is emitted directly
        self::assertStringContainsString(
            "\$data['notes'] = \$this->notes;",
            $orderContent,
        );
        // No null-check wrapping
        self::assertStringNotContainsString(
            'if ($this->notes !== null)',
            $orderContent,
        );
    }

    public function testOmitNullsInToArrayWrapsNullableObject(): void
    {
        $config = $this->makeConfig();
        $config->omitNullsInToArray = true;
        $this->service->generate($config, self::FIXTURES_DIR);

        $orderContent = file_get_contents($this->outputDir . '/Model/Order.php');
        self::assertNotFalse($orderContent);

        // shippingAddress is nullable object → wrapped in if-block calling ->toArray()
        self::assertStringContainsString(
            'if ($this->shippingAddress !== null) {',
            $orderContent,
        );
        self::assertStringContainsString(
            "\$data['shippingAddress'] = \$this->shippingAddress->toArray();",
            $orderContent,
        );
    }

    public function testBuiltinPluginsAppliedByDefault(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = (string) file_get_contents($this->outputDir . '/Model/PluginTestSchema.php');

        // TrimPlugin: x-trim:30 on name → substr in fromArray
        self::assertStringContainsString("substr(\$data['name'], 0, 30)", $content);

        // SensitivePlugin: x-sensitive:true on password → #[\\SensitiveParameter]
        self::assertStringContainsString('#[\SensitiveParameter]', $content);
    }

    public function testDisableBuiltinPluginsSkipsBuiltins(): void
    {
        $config = $this->makeConfig();
        $config->disableBuiltinPlugins = true;
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = (string) file_get_contents($this->outputDir . '/Model/PluginTestSchema.php');

        self::assertStringNotContainsString("substr(\$data['name'], 0, 30)", $content);
        self::assertStringNotContainsString('#[\SensitiveParameter]', $content);
    }

    public function testUserPropertyPluginRunsAfterBuiltins(): void
    {
        $config = $this->makeConfig();
        $markerAdded = false;
        $config->addPropertyPlugin(
            new class ($markerAdded) implements PropertyExtensionPluginInterface {
                public function __construct(private bool &$flag)
                {
                }

                public function process(
                    PropertyExtensionContext $context,
                ): ?PropertyExtensionResult {
                    if ($context->property->wireName === 'name') {
                        $this->flag = true;

                        return new PropertyExtensionResult(
                            extraAttributes: ['#[CustomAttribute]'],
                        );
                    }

                    return null;
                }
            },
        );

        $this->service->generate($config, self::FIXTURES_DIR);

        self::assertTrue($markerAdded, 'User plugin was not invoked');
        $content = (string) file_get_contents($this->outputDir . '/Model/PluginTestSchema.php');
        self::assertStringContainsString('#[CustomAttribute]', $content);

        self::assertStringContainsString("substr(\$data['name'], 0, 30)", $content);
    }

    public function testDisableBuiltinsAllowsCustomPluginOrder(): void
    {
        $config = $this->makeConfig();
        $config->disableBuiltinPlugins = true;
        // Register only a custom plugin – built-ins must NOT run
        $config->addPropertyPlugin(
            new class () implements PropertyExtensionPluginInterface {
                public function process(
                    PropertyExtensionContext $context,
                ): ?PropertyExtensionResult {
                    if ($context->property->wireName === 'password') {
                        return new PropertyExtensionResult(
                            extraAttributes: ['#[MySecureAttribute]'],
                        );
                    }

                    return null;
                }
            },
        );

        $this->service->generate($config, self::FIXTURES_DIR);

        $content = (string) file_get_contents($this->outputDir . '/Model/PluginTestSchema.php');
        // Custom plugin ran
        self::assertStringContainsString('#[MySecureAttribute]', $content);
        // Built-ins did NOT run
        self::assertStringNotContainsString("substr(\$data['name'], 0, 30)", $content);
        self::assertStringNotContainsString('#[\SensitiveParameter]', $content);
    }

    public function testAllGeneratedFilesAreValidPhp(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $modelDir = $this->outputDir . '/Model';
        foreach (glob($modelDir . '/*.php') as $file) {
            $output = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($file) . ' 2>&1');
            self::assertStringContainsString(
                'No syntax errors',
                (string) $output,
                "Syntax error in $file",
            );
        }
    }

    private function makeConfig(): GeneratorConfig
    {
        $config = new GeneratorConfig();
        $config->specFile = 'feature-test.yaml';
        $config->outputDir = $this->outputDir;
        $config->modelNamespace = 'Generated\\Model';
        $config->modelOutputDir = 'Model';
        $config->phpVersion = '8.2';
        $config->phpReadonly = true;
        $config->generateConstructor = true;
        $config->generateFromArray = true;
        $config->generateToArray = true;

        return $config;
    }

    private const FIXTURES_DIR = __DIR__ . '/../Fixtures';

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}
