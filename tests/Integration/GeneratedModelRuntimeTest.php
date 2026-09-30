<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Integration;

use MaxBeckers\OpenApiGenerator\Config\EnumUnknownDefault;
use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;
use MaxBeckers\OpenApiGenerator\Tests\Support\GeneratedCodeTestSupport;
use PHPUnit\Framework\TestCase;

final class GeneratedModelRuntimeTest extends TestCase
{
    use GeneratedCodeTestSupport;

    private const FIXTURES_DIR = __DIR__ . '/../Fixtures';

    /** @var list<\Closure(string): void> */
    private array $autoloaders = [];

    protected function setUp(): void
    {
        $this->initializeGeneratedCodeTestSupport();
    }

    protected function tearDown(): void
    {
        foreach ($this->autoloaders as $autoloader) {
            spl_autoload_unregister($autoloader);
        }
        $this->removeGeneratedCodeOutput();
    }

    public function testRoundTripPreservesDatesArraysNestedObjectsAndAdditionalProperties(): void
    {
        $namespace = $this->generateModels('runtime-models.yaml', static function (GeneratorConfig $config): void {
            $config->typeMapping = ['uuid' => 'string'];
            $config->omitNullsInToArray = false;
        });
        $this->registerModelAutoloader($namespace);

        $input = [
            'name' => 'Ada',
            'identifier' => 'customer-42',
            'createdAt' => '2026-09-29T10:15:30+00:00',
            'birthDate' => '1990-02-03',
            'note' => null,
            'labels' => ['priority', 'verified'],
            'addresses' => [
                ['street' => '1 Main Street', 'city' => 'London'],
                ['street' => '2 High Street', 'city' => 'Oxford'],
            ],
            'metadata' => ['source' => 'import', 'attempts' => '2'],
        ];

        $class = $namespace . '\\RuntimePayload';
        $model = $class::fromArray($input);

        self::assertSame($input['metadata'], $model->metadata->toArray());
        self::assertEqualsCanonicalizing($input, $model->toArray());
        self::assertSame('2026-09-29T10:15:30+00:00', $model->createdAt->format(DATE_ATOM));
        self::assertSame('1990-02-03', $model->birthDate->format('Y-m-d'));
        self::assertCount(2, $model->addresses);
        self::assertSame('London', $model->addresses[0]->city);
    }

    public function testNullableValuesAreOmittedOrIncludedAccordingToConfiguration(): void
    {
        foreach ([true, false] as $omitNulls) {
            $namespace = $this->generateModels(
                'feature-test.yaml',
                static function (GeneratorConfig $config) use ($omitNulls): void {
                    $config->omitNullsInToArray = $omitNulls;
                },
            );
            $this->registerModelAutoloader($namespace);

            $orderClass = $namespace . '\\Order';
            $order = $orderClass::fromArray([
                'id' => 'order-1',
                'status' => 'pending',
                'total' => ['amount' => 250, 'currency' => 'USD'],
                'shippingAddress' => null,
                'notes' => null,
            ]);
            $serialized = $order->toArray();

            self::assertSame('pending', $serialized['status']);
            if ($omitNulls) {
                self::assertArrayNotHasKey('shippingAddress', $serialized);
                self::assertArrayNotHasKey('notes', $serialized);
            } else {
                self::assertArrayHasKey('shippingAddress', $serialized);
                self::assertNull($serialized['shippingAddress']);
                self::assertArrayHasKey('notes', $serialized);
                self::assertNull($serialized['notes']);
            }

            $this->removeGeneratedCodeOutput();
            $this->initializeGeneratedCodeTestSupport();
        }
    }

    public function testEnumUnknownValuesFollowAllConfiguredPolicies(): void
    {
        foreach ([
            [EnumUnknownDefault::Null, null],
            [EnumUnknownDefault::Throw, \ValueError::class],
            [EnumUnknownDefault::Raw, 'pending'],
        ] as [$policy, $expected]) {
            $namespace = $this->generateModels(
                'feature-test.yaml',
                static function (GeneratorConfig $config) use ($policy): void {
                    $config->enumUnknownDefault = $policy;
                },
            );
            $this->registerModelAutoloader($namespace);

            $enumClass = $namespace . '\\OrderStatus';
            if ($expected === \ValueError::class) {
                try {
                    $enumClass::fromArray('unknown');
                    self::fail('Throw policy must reject unknown enum values.');
                } catch (\ValueError) {
                    self::assertTrue(true);
                }
            } elseif ($expected === null) {
                self::assertNull($enumClass::fromArray('unknown'));
            } else {
                self::assertSame($expected, $enumClass::fromArray('unknown')->value);
            }

            $this->removeGeneratedCodeOutput();
            $this->initializeGeneratedCodeTestSupport();
        }
    }

    public function testMissingRequiredEnumValueThrowsDuringHydration(): void
    {
        $namespace = $this->generateModels('feature-test.yaml');
        $this->registerModelAutoloader($namespace);

        $orderClass = $namespace . '\\Order';
        $this->expectException(\InvalidArgumentException::class);
        $orderClass::fromArray([
            'id' => 'order-1',
            'total' => ['amount' => 250, 'currency' => 'USD'],
        ]);
    }

    public function testCompositionAndDiscriminatorModelsExecuteAndRoundTrip(): void
    {
        $namespace = $this->generateModels('composition-test.yaml');
        $this->registerModelAutoloader($namespace);

        $animalClass = $namespace . '\\Animal';
        $dog = $animalClass::fromArray(['type' => 'dog', 'breed' => 'collie']);
        self::assertInstanceOf($namespace . '\\Dog', $dog);
        self::assertSame(['type' => 'dog', 'breed' => 'collie'], $dog->toArray());

        $cat = $animalClass::fromArray(['type' => 'cat', 'indoor' => true]);
        self::assertInstanceOf($namespace . '\\Cat', $cat);
        $unknown = $animalClass::fromArray(['type' => 'bird']);
        self::assertInstanceOf($animalClass, $unknown);

        $addressClass = $namespace . '\\NamedAddress';
        $address = $addressClass::fromArray([
            'street' => '1 Main Street',
            'city' => 'London',
            'label' => 'home',
        ]);
        self::assertSame(
            ['street' => '1 Main Street', 'city' => 'London', 'label' => 'home'],
            $address->toArray(),
        );
    }

    public function testUndiscriminatedCompositionFailsRatherThanSilentlyLosingInput(): void
    {
        $namespace = $this->generateModels('composition-test.yaml');
        $this->registerModelAutoloader($namespace);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot hydrate UnlabelledAnimal without a discriminator.');
        ($namespace . '\\UnlabelledAnimal')::fromArray(['type' => 'dog', 'breed' => 'collie']);
    }

    public function testCircularTreeRoundTripsNestedChildren(): void
    {
        $namespace = $this->generateModels('composition-test.yaml');
        $this->registerModelAutoloader($namespace);

        $nodeClass = $namespace . '\\Node';
        $input = [
            'value' => 'root',
            'children' => [
                ['value' => 'child', 'children' => []],
            ],
        ];

        $node = $nodeClass::fromArray($input);
        self::assertSame([
            'value' => 'root',
            'children' => [
                ['value' => 'child', 'children' => []],
            ],
        ], $node->toArray());
        self::assertNull($node->parent);
        self::assertSame('child', $node->children[0]->value);
    }

    public function testDirectionalSerializationFiltersReadOnlyAndWriteOnlyFields(): void
    {
        $namespace = $this->generateModels(
            'read-write-flags.yaml',
            static function (GeneratorConfig $config): void {
                $config->splitReadWriteDtos = true;
            },
        );
        $this->registerModelAutoloader($namespace);

        $accountClass = $namespace . '\\Account';
        $account = $accountClass::fromArray([
            'id' => 17,
            'name' => 'Ada',
            'password' => 'secret-password',
            'nickname' => null,
        ]);

        self::assertSame(['name' => 'Ada', 'password' => 'secret-password'], $account->toRequestArray());
        self::assertSame(['id' => 17, 'name' => 'Ada'], $account->toResponseArray());
        self::assertArrayNotHasKey('id', $accountClass::fromRequestArray([
            'id' => 99,
            'name' => 'Ada',
            'password' => 'secret-password',
        ])->toRequestArray());
        self::assertArrayNotHasKey('password', $accountClass::fromResponseArray([
            'id' => 17,
            'name' => 'Ada',
            'password' => 'should-be-removed',
        ])->toResponseArray());
    }

    public function testBuiltinPluginsTransformValuesAndExposeSensitiveParameterAttribute(): void
    {
        $namespace = $this->generateModels('feature-test.yaml');
        $this->registerModelAutoloader($namespace);

        $pluginClass = $namespace . '\\PluginTestSchema';
        $model = $pluginClass::fromArray([
            'name' => str_repeat('x', 35),
            'password' => 'secret',
        ]);

        self::assertSame(30, strlen($model->name));
        $constructor = new \ReflectionMethod($pluginClass, '__construct');
        $password = $constructor->getParameters()[1];
        self::assertSame(
            [\SensitiveParameter::class],
            array_map(static fn (\ReflectionAttribute $attribute): string => $attribute->getName(), $password->getAttributes()),
        );
    }

    /**
     * @param (\Closure(GeneratorConfig): void)|null $configure
     */
    private function generateModels(string $fixture, ?\Closure $configure = null): string
    {
        $suffix = bin2hex(random_bytes(6));
        $namespace = 'Generated\\Runtime' . $suffix;
        $config = new GeneratorConfig();
        $config->specFile = $fixture;
        $config->outputDir = $this->generatedCodeOutputDir;
        $config->modelNamespace = $namespace;
        $config->modelOutputDir = 'Model';
        $config->apiNamespace = '';
        $config->apiOutputDir = '';
        $config->generationTarget = GenerationTarget::Server;
        $config->generateFromArray = true;
        $config->generateToArray = true;
        $config->phpVersion = '8.2';
        $configure?->__invoke($config);

        $output = $this->generateCode($config, self::FIXTURES_DIR, false);
        $this->assertAllGeneratedFilesLint($output);

        return $namespace;
    }

    private function registerModelAutoloader(string $namespace): void
    {
        $prefix = $namespace . '\\';
        $modelDirectory = $this->generatedCodeOutputDir . DIRECTORY_SEPARATOR . 'Model';
        $loader = static function (string $class) use ($prefix, $modelDirectory): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relativeClass = substr($class, strlen($prefix));
            $path = $modelDirectory . DIRECTORY_SEPARATOR
                . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        };

        spl_autoload_register($loader);
        $this->autoloaders[] = $loader;
    }
}
