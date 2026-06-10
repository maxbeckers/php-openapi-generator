<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Integration;

use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\FileWriter\FileWriter;
use MaxBeckers\OpenApiGenerator\Loader\OpenApiLoader;
use MaxBeckers\OpenApiGenerator\Service\OpenApiService;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for composition patterns (allOf inheritance, oneOf discriminator
 * interfaces, inline hoisting) using the composition-test.yaml fixture.
 */
class CompositionGenerationTest extends TestCase
{
    private string $outputDir;
    private OpenApiService $service;
    private static OpenApiService $sharedService;

    private const FIXTURES_DIR = __DIR__ . '/../Fixtures';

    public static function setUpBeforeClass(): void
    {
        self::$sharedService = new OpenApiService(new OpenApiLoader(), new FileWriter());
    }

    protected function setUp(): void
    {
        $this->outputDir = sys_get_temp_dir() . '/openapi-gen-comp-' . uniqid('', true);
        $this->service = self::$sharedService;
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->outputDir);
    }

    // =========================================================================
    // allOf single-ref inheritance
    // =========================================================================

    public function testAllOfChildExtendsParentClass(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readModel('NamedAddress.php');

        self::assertStringContainsString('class NamedAddress extends Address', $content);
    }

    public function testAllOfChildConstructorCallsParent(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readModel('NamedAddress.php');

        self::assertStringContainsString('parent::__construct(', $content);
    }

    public function testAllOfChildOnlyHasOwnPropertyInConstructor(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readModel('NamedAddress.php');

        // Child constructor must include inherited + own properties.
        self::assertStringContainsString('$label', $content);
        self::assertStringContainsString('public string $street', $content);
        self::assertStringContainsString('public string $city', $content);
    }

    public function testAllOfChildConstructorForwardsInheritedParametersToParent(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readModel('NamedAddress.php');

        self::assertStringContainsString('parent::__construct(', $content);
        self::assertStringContainsString('street: $street', $content);
        self::assertStringContainsString('city: $city', $content);
    }

    public function testPetAllOfChildConstructorContainsParentAndOwnFields(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readModel('DogPet.php');

        self::assertStringContainsString('class DogPet extends Pet', $content);
        self::assertStringContainsString('public string $id', $content);
        self::assertStringContainsString('public string $name', $content);
        self::assertStringContainsString('public string $breed', $content);
    }

    public function testPetAllOfChildForwardsOnlyParentArgsToParentConstructor(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readModel('DogPet.php');

        self::assertMatchesRegularExpression('/parent::__construct\(\s*id: \$id,\s*name: \$name,\s*\);/s', $content);
        self::assertDoesNotMatchRegularExpression('/parent::__construct\([^)]*breed:/s', $content);
        self::assertDoesNotMatchRegularExpression('/parent::__construct\([^)]*age:/s', $content);
    }

    public function testAllOfParentToArrayIsChained(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readModel('NamedAddress.php');

        self::assertStringContainsString('$data = parent::toArray()', $content);
    }

    public function testAllOfChildFilesAreValidPhp(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        foreach (['Address.php', 'NamedAddress.php', 'Pet.php', 'DogPet.php'] as $file) {
            $path = $this->outputDir . '/Model/' . $file;
            $output = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($path) . ' 2>&1');
            self::assertStringContainsString('No syntax errors', (string) $output, "Syntax error in $file");
        }
    }

    // =========================================================================
    // oneOf + discriminator → interface
    // =========================================================================

    public function testDiscriminatorGeneratesInterface(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        // The default interfaceSuffix is 'Interface', so Animal → AnimalInterface
        self::assertFileExists($this->outputDir . '/Model/AnimalInterface.php');
        $content = $this->readModel('AnimalInterface.php');
        self::assertStringContainsString('interface AnimalInterface', $content);
    }

    public function testDiscriminatorImplementorsImplementInterface(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $dog = $this->readModel('Dog.php');
        $cat = $this->readModel('Cat.php');

        self::assertStringContainsString('implements AnimalInterface', $dog);
        self::assertStringContainsString('implements AnimalInterface', $cat);
    }

    public function testDiscriminatorInterfaceHasFromArrayFactory(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readModel('AnimalInterface.php');

        self::assertStringContainsString('public static function fromArray', $content);
    }

    public function testDiscriminatorFilesAreValidPhp(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        foreach (['AnimalInterface.php', 'Dog.php', 'Cat.php'] as $file) {
            $path = $this->outputDir . '/Model/' . $file;
            $output = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($path) . ' 2>&1');
            self::assertStringContainsString('No syntax errors', (string) $output, "Syntax error in $file");
        }
    }

    // =========================================================================
    // Inline object hoisting
    // =========================================================================

    public function testInlineObjectPropertyIsHoisted(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        // Order has an inline 'address' object → hoisted as OrderAddress
        self::assertFileExists($this->outputDir . '/Model/OrderAddress.php');
    }

    public function testHoistedClassIsValidPhpAndAnObject(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readModel('OrderAddress.php');

        $output = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($this->outputDir . '/Model/OrderAddress.php') . ' 2>&1');
        self::assertStringContainsString('No syntax errors', (string) $output);
        self::assertStringContainsString('class OrderAddress', $content);
    }

    public function testOrderReferencesHoistedAddress(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $orderContent = $this->readModel('Order.php');

        // The Order class should use OrderAddress as the type for the address property
        self::assertStringContainsString('OrderAddress', $orderContent);
    }

    // =========================================================================
    // Circular reference
    // =========================================================================

    public function testCircularRefPropertyDefaultsToNull(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readModel('Node.php');

        // Circular direct ref → nullable with default null
        self::assertStringContainsString('?Node $parent = null', $content);
    }

    public function testCircularArrayRefDefaultsToEmptyArray(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readModel('Node.php');

        // Circular array ref → defaults to empty array
        self::assertStringContainsString('array $children = []', $content);
    }

    public function testNodeFileIsValidPhp(): void
    {
        $config = $this->makeConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $path = $this->outputDir . '/Model/Node.php';
        $output = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($path) . ' 2>&1');
        self::assertStringContainsString('No syntax errors', (string) $output);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeConfig(): GeneratorConfig
    {
        $config = new GeneratorConfig();
        $config->specFile = 'composition-test.yaml';
        $config->outputDir = $this->outputDir;
        $config->modelNamespace = 'Generated\\Model';
        $config->modelOutputDir = 'Model';
        $config->phpVersion = '8.2';
        $config->generateFromArray = true;
        $config->generateToArray = true;
        $config->phpReadonly = true;

        return $config;
    }

    private function readModel(string $filename): string
    {
        $path = $this->outputDir . '/Model/' . $filename;
        self::assertFileExists($path, "Expected generated file $filename does not exist");
        $content = file_get_contents($path);
        self::assertNotFalse($content);

        return $content;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}
