<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Integration;

use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\FileWriter\FileWriter;
use MaxBeckers\OpenApiGenerator\Loader\OpenApiLoader;
use MaxBeckers\OpenApiGenerator\Service\OpenApiService;
use PHPUnit\Framework\TestCase;

class ExtendedPetRegressionGenerationTest extends TestCase
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
        $this->outputDir = sys_get_temp_dir() . '/openapi-extended-pet-regression-' . uniqid('', true);
        $this->service = self::$sharedService;
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->outputDir);
    }

    public function testPrimitiveArrayFromArrayDoesNotCallScalarFactory(): void
    {
        $this->service->generate($this->makeConfig(), self::FIXTURES_DIR);

        $adventure = $this->readModel('Adventure.php');

        self::assertStringContainsString("tags: \$data['tags'] ?? []", $adventure);
        self::assertStringNotContainsString('string::fromArray', $adventure);
    }

    public function testPrimitiveArrayToArrayDoesNotCallItemToArray(): void
    {
        $this->service->generate($this->makeConfig(), self::FIXTURES_DIR);

        $adventure = $this->readModel('Adventure.php');

        self::assertStringContainsString("\$data['tags'] = \$this->tags;", $adventure);
        self::assertStringNotContainsString("\$data['tags'] = array_map(static fn(\$item) => \$item->toArray()", $adventure);
    }

    public function testDiscriminatorArrayDoesNotGenerateMixedFactoryCall(): void
    {
        $this->service->generate($this->makeConfig(), self::FIXTURES_DIR);

        $adventure = $this->readModel('Adventure.php');

        self::assertStringNotContainsString('mixed::fromArray', $adventure);
        self::assertStringContainsString('FightStage::fromArray($item)', $adventure);
        self::assertStringContainsString('TalkStage::fromArray($item)', $adventure);
    }

    public function testDiscriminatorOnlyBaseConditionClassIsGeneratedWithDispatch(): void
    {
        $this->service->generate($this->makeConfig(), self::FIXTURES_DIR);

        $base = $this->readModel('OptionCondition.php');
        $option = $this->readModel('PetOption.php');
        $this->readModel('HasTagCondition.php');
        $this->readModel('RelationshipMinCondition.php');
        $this->readModel('TrainingCompletedCondition.php');

        self::assertStringContainsString('class OptionCondition', $base);
        self::assertStringContainsString("return match (\$data['type'])", $base);
        self::assertStringContainsString('HasTagCondition::fromArray($data)', $base);
        self::assertStringContainsString('RelationshipMinCondition::fromArray($data)', $base);
        self::assertStringContainsString('TrainingCompletedCondition::fromArray($data)', $base);
        self::assertStringContainsString('OptionCondition::fromArray($item)', $option);
    }

    public function testOneOfDiscriminatorBaseClassWorksWithInlineEnums(): void
    {
        $this->service->generate($this->makeConfig(), self::FIXTURES_DIR);

        $base = $this->readModel('InlineDecisionCondition.php');
        $option = $this->readModel('PetOption.php');

        self::assertStringContainsString('class InlineDecisionCondition', $base);
        self::assertStringContainsString("return match (\$data['type'])", $base);
        self::assertStringContainsString('MoodCondition::fromArray($data)', $base);
        self::assertStringContainsString('TrustMinCondition::fromArray($data)', $base);
        self::assertStringContainsString('InlineDecisionCondition::fromArray($item)', $option);

        $this->requireModels([
            'InlineDecisionCondition.php',
            'MoodCondition.php',
            'TrustMinCondition.php',
        ]);

        $baseClass = 'Generated\\Model\\InlineDecisionCondition';
        $condition = $baseClass::fromArray([
            'type' => 'mood',
            'topic' => 'playful',
        ]);

        self::assertInstanceOf('Generated\\Model\\MoodCondition', $condition);
        self::assertSame(['type' => 'mood', 'topic' => 'playful'], $condition->toArray());
    }

    public function testGenericEffectDispatchPreservesPayloadInRoundTrip(): void
    {
        $this->service->generate($this->makeConfig(), self::FIXTURES_DIR);

        $this->requireModels([
            'PetEffect.php',
            'SetTraitEffect.php',
            'AddBondEffect.php',
        ]);

        $effectClass = 'Generated\\Model\\PetEffect';

        $input = [
            'type' => 'set_trait',
            'trait' => 'curious',
            'value' => true,
        ];

        /** @var object $effect */
        $effect = $effectClass::fromArray($input);

        self::assertInstanceOf('Generated\\Model\\SetTraitEffect', $effect);
        self::assertSame($input, $effect->toArray());
    }

    public function testNestedAllOfReferenceKeepsComposedFields(): void
    {
        $this->service->generate($this->makeConfig(), self::FIXTURES_DIR);

        $effect = $this->readModel('CombinedEffect.php');

        self::assertStringContainsString('public ?string $tag = null', $effect);
        self::assertStringContainsString('public ?int $value = null', $effect);
        self::assertStringContainsString('public ?string $companion = null', $effect);
        self::assertStringContainsString('public ?string $faction = null', $effect);
    }

    public function testGenericTreeRootClassIsGenerated(): void
    {
        $this->service->generate($this->makeConfig(), self::FIXTURES_DIR);

        $tree = $this->readModel('PetTree.php');
        self::assertStringContainsString('class PetTree', $tree);
        self::assertStringContainsString('public PetNode $root', $tree);
    }

    private function makeConfig(): GeneratorConfig
    {
        $config = new GeneratorConfig();
        $config->specFile = 'extended-pet-regression.yaml';
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
