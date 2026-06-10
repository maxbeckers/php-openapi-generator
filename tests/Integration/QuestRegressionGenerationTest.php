<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Integration;

use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\FileWriter\FileWriter;
use MaxBeckers\OpenApiGenerator\Loader\OpenApiLoader;
use MaxBeckers\OpenApiGenerator\Service\OpenApiService;
use PHPUnit\Framework\TestCase;

class QuestRegressionGenerationTest extends TestCase
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
        $this->outputDir = sys_get_temp_dir() . '/openapi-quest-regression-' . uniqid('', true);
        $this->service = self::$sharedService;
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->outputDir);
    }

    public function testPrimitiveArrayFromArrayDoesNotCallScalarFactory(): void
    {
        $this->service->generate($this->makeConfig(), self::FIXTURES_DIR);

        $quest = $this->readModel('Quest.php');

        self::assertStringContainsString("tags: \$data['tags'] ?? []", $quest);
        self::assertStringNotContainsString('string::fromArray', $quest);
    }

    public function testPrimitiveArrayToArrayDoesNotCallItemToArray(): void
    {
        $this->service->generate($this->makeConfig(), self::FIXTURES_DIR);

        $quest = $this->readModel('Quest.php');

        self::assertStringContainsString("\$data['tags'] = \$this->tags;", $quest);
        self::assertStringNotContainsString("\$data['tags'] = array_map(static fn(\$item) => \$item->toArray()", $quest);
    }

    public function testDiscriminatorArrayDoesNotGenerateMixedFactoryCall(): void
    {
        $this->service->generate($this->makeConfig(), self::FIXTURES_DIR);

        $quest = $this->readModel('Quest.php');

        self::assertStringNotContainsString('mixed::fromArray', $quest);
        self::assertStringContainsString('CombatStep::fromArray($item)', $quest);
        self::assertStringContainsString('DialogueStep::fromArray($item)', $quest);
    }

    public function testNestedAllOfReferenceKeepsComposedFields(): void
    {
        $this->service->generate($this->makeConfig(), self::FIXTURES_DIR);

        $effect = $this->readModel('DialogueEffect.php');

        self::assertStringContainsString('public ?string $flag = null', $effect);
        self::assertStringContainsString('public ?int $value = null', $effect);
        self::assertStringContainsString('public ?string $npc = null', $effect);
        self::assertStringContainsString('public ?string $faction = null', $effect);
    }

    private function makeConfig(): GeneratorConfig
    {
        $config = new GeneratorConfig();
        $config->specFile = 'quest-regression.yaml';
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
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }

        rmdir($dir);
    }
}
