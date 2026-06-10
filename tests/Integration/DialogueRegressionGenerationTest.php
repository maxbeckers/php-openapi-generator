<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Integration;

use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\FileWriter\FileWriter;
use MaxBeckers\OpenApiGenerator\Loader\OpenApiLoader;
use MaxBeckers\OpenApiGenerator\Service\OpenApiService;
use PHPUnit\Framework\TestCase;

class DialogueRegressionGenerationTest extends TestCase
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
        $this->outputDir = sys_get_temp_dir() . '/openapi-dialogue-regression-' . uniqid('', true);
        $this->service = self::$sharedService;
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->outputDir);
    }

    public function testDiscriminatorOnlyBaseConditionClassIsGeneratedWithDispatch(): void
    {
        $this->service->generate($this->makeConfig(), self::FIXTURES_DIR);

        $base = $this->readModel('DialogueCondition.php');
        $choice = $this->readModel('DialogueChoice.php');

        self::assertStringContainsString('class DialogueCondition', $base);
        self::assertStringContainsString('return match ($data[\'type\'])', $base);
        self::assertStringContainsString('FlagDialogueCondition::fromArray($data)', $base);
        self::assertStringContainsString('QuestStateDialogueCondition::fromArray($data)', $base);
        self::assertStringContainsString('DialogueCondition::fromArray($item)', $choice);
    }

    public function testDialogueEffectDispatchPreservesPayloadInRoundTrip(): void
    {
        $this->service->generate($this->makeConfig(), self::FIXTURES_DIR);

        $this->requireModels([
            'DialogueEffect.php',
            'SetFlagEffect.php',
            'AddRelationshipEffect.php',
        ]);

        $effectClass = 'Generated\\Model\\DialogueEffect';

        $input = [
            'type' => 'set_flag',
            'flag' => 'intro_done',
            'value' => true,
        ];

        /** @var object $effect */
        $effect = $effectClass::fromArray($input);

        self::assertInstanceOf('Generated\\Model\\SetFlagEffect', $effect);
        self::assertSame($input, $effect->toArray());
    }

    private function makeConfig(): GeneratorConfig
    {
        $config = new GeneratorConfig();
        $config->specFile = 'dialogue-regression.yaml';
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

    /**
     * @param string[] $filenames
     */
    private function requireModels(array $filenames): void
    {
        foreach ($filenames as $filename) {
            require_once $this->outputDir . '/Model/' . $filename;
        }
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
