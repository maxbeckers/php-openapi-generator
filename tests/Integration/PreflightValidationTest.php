<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Integration;

use MaxBeckers\OpenApiGenerator\Command\GenerateCommand;
use MaxBeckers\OpenApiGenerator\Command\ValidateCommand;
use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;
use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\FileWriter\FileWriter;
use MaxBeckers\OpenApiGenerator\Loader\OpenApiLoader;
use MaxBeckers\OpenApiGenerator\Service\OpenApiService;
use MaxBeckers\OpenApiGenerator\Validation\Diagnostic;
use MaxBeckers\OpenApiGenerator\Validation\DiagnosticBag;
use MaxBeckers\OpenApiGenerator\Validation\PreflightValidator;
use MaxBeckers\OpenApiGenerator\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PreflightValidationTest extends TestCase
{
    private string $directory;
    private OpenApiService $service;
    private GeneratorConfig $config;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'openapi-preflight-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->service = new OpenApiService(new OpenApiLoader(), new FileWriter());
        $this->config = new GeneratorConfig();
        $this->config->specFile = 'spec.json';
        $this->config->outputDir = 'output';
        $this->config->phpVersion = '8.2';
        $this->config->apiNamespace = 'Generated\\Api';
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testMalformedFieldsAreAggregatedAndParentErrorsSuppressChildren(): void
    {
        $spec = $this->spec();
        $spec['info']['title'] = [];
        $spec['paths']['/pets/~active'] = [
            'get' => ['parameters' => 'bad', 'responses' => ['200' => ['content' => ['application/json' => ['schema' => ['minimum' => []]]]]]],
            'post' => 'bad',
        ];
        $spec['components']['schemas']['Bad'] = ['properties' => 'bad', 'required' => [42]];
        $this->writeSpec($spec);

        $diagnostics = $this->service->validate($this->config, $this->directory);

        self::assertTrue($diagnostics->hasErrors());
        self::assertSame([
            '/components/schemas/Bad/properties',
            '/components/schemas/Bad/required/0',
            '/info/title',
            '/paths/~1pets~1~0active/get/parameters',
            '/paths/~1pets~1~0active/get/responses/200/content/application~1json/schema/minimum',
            '/paths/~1pets~1~0active/post',
        ], array_column($diagnostics->all(), 'pointer'));
        self::assertDirectoryDoesNotExist($this->directory . DIRECTORY_SEPARATOR . 'output');
    }

    public function testGenerationDoesNotChangeExistingFilesOnInvalidInput(): void
    {
        $this->writeSpec($this->spec());
        $this->service->generate($this->config, $this->directory);
        $model = $this->directory . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'Model' . DIRECTORY_SEPARATOR . 'Pet.php';
        $before = file_get_contents($model);
        $spec = $this->spec();
        $spec['components']['schemas']['Pet']['properties'] = 'bad';
        $this->writeSpec($spec);

        try {
            $this->service->generate($this->config, $this->directory);
            self::fail('Invalid input must fail.');
        } catch (ValidationException $exception) {
            self::assertTrue($exception->diagnostics->hasErrors());
            self::assertSame($before, file_get_contents($model));
        }
    }

    public function testMissingReferencesAreAggregated(): void
    {
        $spec = $this->spec();
        $spec['components']['schemas']['MissingLocal'] = ['$ref' => '#/components/schemas/Missing'];
        $spec['components']['schemas']['MissingFile'] = ['$ref' => 'missing.json#/components/schemas/Missing'];
        $this->writeSpec($spec);

        $diagnostics = $this->service->validate($this->config, $this->directory);

        self::assertTrue($diagnostics->hasErrors());
        self::assertCount(2, $diagnostics->all());
        self::assertSame(['reference.invalid', 'reference.invalid'], array_column($diagnostics->all(), 'code'));
        self::assertStringContainsString('was not found', (string) $diagnostics->all()[1]);
        self::assertStringContainsString('Referenced OpenAPI file not found', (string) $diagnostics->all()[0]);
    }

    public function testExternalFieldDiagnosticsPointToTheOriginalDocumentAndFragment(): void
    {
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'models.json', json_encode([
            'definitions' => ['Pet' => ['type' => 'object', 'properties' => ['id' => ['minimum' => []]]]],
        ], JSON_THROW_ON_ERROR));
        $spec = $this->spec();
        $spec['components']['schemas']['Pet'] = ['$ref' => 'models.json#/definitions/Pet'];
        $this->writeSpec($spec);

        $diagnostics = $this->service->validate($this->config, $this->directory);

        self::assertCount(1, $diagnostics->all());
        $diagnostic = $diagnostics->all()[0];
        self::assertSame(realpath($this->directory . DIRECTORY_SEPARATOR . 'models.json'), $diagnostic->document);
        self::assertSame('/definitions/Pet/properties/id/minimum', $diagnostic->pointer);
    }

    public function testNestedExternalReferenceFailureUsesItsOriginalPointer(): void
    {
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'models.json', json_encode([
            'definitions' => ['Pet' => ['type' => 'object', 'properties' => ['id' => ['$ref' => '#/missing']]]],
        ], JSON_THROW_ON_ERROR));
        $spec = $this->spec();
        $spec['components']['schemas']['Pet'] = ['$ref' => 'models.json#/definitions/Pet'];
        $this->writeSpec($spec);

        $diagnostics = $this->service->validate($this->config, $this->directory);

        self::assertCount(1, $diagnostics->all());
        self::assertSame(realpath($this->directory . DIRECTORY_SEPARATOR . 'models.json'), $diagnostics->all()[0]->document);
        self::assertSame('/definitions/Pet/properties/id/$ref', $diagnostics->all()[0]->pointer);
    }

    public function testCompatibilityChecksWarnByDefaultAndBlockWhenStrict(): void
    {
        $spec = $this->spec();
        $spec['openapi'] = '2.0';
        $spec['components']['schemas']['Pet']['not'] = ['type' => 'string'];
        $this->writeSpec($spec);
        $this->config->phpVersion = '8.1';

        $diagnostics = $this->service->generate($this->config, $this->directory);
        self::assertFalse($diagnostics->hasErrors());
        self::assertSame(['warning'], array_values(array_unique(array_column($diagnostics->all(), 'severity'))));
        self::assertContains('openapi.version', array_column($diagnostics->all(), 'code'));
        self::assertContains('feature.unsupported', array_column($diagnostics->all(), 'code'));

        $this->config->setStrictSpecValidation(true);
        self::assertTrue($this->service->validate($this->config, $this->directory)->hasErrors());
    }

    public function testCapabilityChecksRespectFiltersButStructureChecksDoNot(): void
    {
        $spec = $this->spec();
        $spec['paths']['/bad'] = ['post' => [
            'operationId' => 'bad',
            'requestBody' => ['content' => ['text/plain' => ['schema' => ['type' => 'string']]]],
            'responses' => ['204' => ['description' => 'Done']],
        ]];
        $this->writeSpec($spec);
        $this->config->generationTarget = GenerationTarget::Client;
        $this->config->strictSpecValidation = true;
        self::assertTrue($this->service->validate($this->config, $this->directory)->hasErrors());

        $this->config->excludePaths = ['/bad'];
        self::assertFalse($this->service->validate($this->config, $this->directory)->hasErrors());

        $spec['paths']['/bad']['post']['parameters'] = false;
        $this->writeSpec($spec);
        $diagnostics = $this->service->validate($this->config, $this->directory);
        self::assertTrue($diagnostics->hasErrors());
        self::assertSame('/paths/~1bad/post/parameters', $diagnostics->all()[0]->pointer);
    }

    public function testNameCollisionsAreAggregatedBeforeGeneration(): void
    {
        $spec = $this->spec();
        $spec['components']['schemas']['pet'] = ['type' => 'object'];
        $spec['components']['schemas']['Pet']['properties'] = ['a-b' => ['type' => 'string'], 'a_b' => ['type' => 'string']];
        $spec['paths']['/also-pets'] = ['get' => ['operationId' => 'listPets', 'responses' => ['204' => ['description' => 'Done']]]];
        $this->writeSpec($spec);

        $codes = array_column($this->service->validate($this->config, $this->directory)->all(), 'code');
        self::assertContains('name.schema-collision', $codes);
        self::assertContains('name.property-collision', $codes);
        self::assertContains('name.operation-collision', $codes);
    }

    public function testInlineNameCollisionIsDetected(): void
    {
        $spec = $this->spec();
        $spec['components']['schemas']['Pet']['properties']['address'] = ['type' => 'object'];
        $spec['components']['schemas']['PetAddress'] = ['type' => 'object'];
        $this->writeSpec($spec);

        self::assertContains('name.schema-collision', array_column($this->service->validate($this->config, $this->directory)->all(), 'code'));
    }

    public function testModelOnlyGenerationDoesNotCheckApiRepresentations(): void
    {
        $spec = $this->spec();
        $spec['paths']['/pets']['get']['requestBody'] = ['content' => ['text/plain' => ['schema' => ['type' => 'string']]]];
        $this->writeSpec($spec);
        $this->config->apiNamespace = '';
        $this->config->strictSpecValidation = true;

        self::assertFalse($this->service->validate($this->config, $this->directory)->hasErrors());
    }

    public function testMalformedFilterEntriesFailBeforeFiltering(): void
    {
        $this->writeSpec($this->spec());
        // @phpstan-ignore-next-line Deliberately violate the PHPDoc shape to test runtime configuration diagnostics.
        $this->config->includePaths = [42];

        $diagnostics = $this->service->validate($this->config, $this->directory);

        self::assertTrue($diagnostics->hasErrors());
        self::assertSame('config.type', $diagnostics->all()[0]->code);
        self::assertSame('/includePaths/0', $diagnostics->all()[0]->pointer);
    }

    public function testUnknownDynamicConfigOptionsAreDiagnosed(): void
    {
        $config = new #[\AllowDynamicProperties] class () extends GeneratorConfig {
        };
        // @phpstan-ignore-next-line Deliberately misspell an option to exercise typo diagnostics.
        $config->generteFromArray = false;
        $diagnostics = new DiagnosticBag();
        (new PreflightValidator())->validateConfig($config, $diagnostics);

        self::assertSame('config.unknown-option', $diagnostics->all()[0]->code);
        self::assertSame('warning', $diagnostics->all()[0]->severity);

        $config->strictSpecValidation = true;
        $diagnostics = new DiagnosticBag();
        (new PreflightValidator())->validateConfig($config, $diagnostics);
        self::assertTrue($diagnostics->hasErrors());
    }

    public function testBooleanSchemasAreReportedAsUnsupportedNotMalformed(): void
    {
        $spec = $this->spec();
        $spec['openapi'] = '3.1.0';
        $spec['components']['schemas']['Anything'] = true;
        $this->writeSpec($spec);

        $diagnostics = $this->service->validate($this->config, $this->directory);

        self::assertSame('feature.boolean-schema', $diagnostics->all()[0]->code);
        self::assertTrue($diagnostics->hasErrors());
    }

    public function testEnumBackingTypesAndCaseCollisionsAreDiagnosed(): void
    {
        $spec = $this->spec();
        $spec['components']['schemas']['Enum'] = ['enum' => ['a-b', 'a_b', 1, []]];
        $this->writeSpec($spec);

        $codes = array_column($this->service->validate($this->config, $this->directory)->all(), 'code');

        self::assertContains('name.enum-collision', $codes);
        self::assertCount(2, array_filter($codes, static fn (string $code): bool => $code === 'representation.enum'));
    }

    public function testSymbolsInSharedNamespacesCannotCollideEvenInDifferentDirectories(): void
    {
        $spec = $this->spec();
        $spec['components']['schemas']['DefaultApiClient'] = ['type' => 'object'];
        $this->config->generationTarget = GenerationTarget::Client;
        $this->config->apiNamespace = $this->config->modelNamespace;
        $this->config->apiOutputDir = 'Api';
        $this->writeSpec($spec);

        $diagnostics = $this->service->validate($this->config, $this->directory);

        self::assertTrue($diagnostics->hasErrors());
        self::assertContains('name.symbol-collision', array_column($diagnostics->all(), 'code'));
    }

    public function testReferencedBodyAndInheritedParameterDiagnosticsHaveRealPointers(): void
    {
        $spec = $this->spec();
        $spec['components']['requestBodies']['Upload'] = ['content' => ['text/plain' => ['schema' => ['type' => 'string']]]];
        $spec['paths']['/pets']['parameters'] = [['name' => 'id', 'in' => 'path', 'required' => false]];
        $spec['paths']['/pets']['get']['requestBody'] = ['$ref' => '#/components/requestBodies/Upload'];
        $this->writeSpec($spec);

        $pointers = array_column($this->service->validate($this->config, $this->directory)->all(), 'pointer');

        self::assertContains('/components/requestBodies/Upload/content/text~1plain', $pointers);
        self::assertContains('/paths/~1pets/parameters/0/required', $pointers);
    }

    public function testFileCollisionDoesNotOverwriteModels(): void
    {
        $spec = $this->spec();
        $spec['components']['schemas']['DefaultApiClient'] = ['type' => 'object'];
        $this->config->generationTarget = GenerationTarget::Client;
        $this->config->apiOutputDir = 'Model';
        $this->writeSpec($spec);

        $diagnostics = $this->service->validate($this->config, $this->directory);

        self::assertTrue($diagnostics->hasErrors());
        self::assertContains('name.file-collision', array_column($diagnostics->all(), 'code'));
        self::assertDirectoryDoesNotExist($this->directory . DIRECTORY_SEPARATOR . 'output');
    }

    public function testValidateCommandSharesOverridesAndDoesNotCreateOutput(): void
    {
        $this->writeSpec($this->spec());
        $this->writeConfig();
        $tester = new CommandTester(new ValidateCommand());

        self::assertSame(Command::SUCCESS, $tester->execute(['--config' => $this->directory . DIRECTORY_SEPARATOR . 'config.php', '--target' => 'client', '--http-client' => 'psr18', '--strict' => true]));
        self::assertStringContainsString('Validation passed.', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($this->directory . DIRECTORY_SEPARATOR . 'output');
    }

    public function testCommandsReportStrictFailuresAndWarnings(): void
    {
        $spec = $this->spec();
        $spec['openapi'] = '9.0.0';
        $this->writeSpec($spec);
        $this->writeConfig();
        $options = ['--config' => $this->directory . DIRECTORY_SEPARATOR . 'config.php'];

        $validate = new CommandTester(new ValidateCommand());
        self::assertSame(Command::FAILURE, $validate->execute($options + ['--strict' => true]));
        self::assertStringContainsString('openapi.version', $validate->getDisplay());
        self::assertDirectoryDoesNotExist($this->directory . DIRECTORY_SEPARATOR . 'output');

        $generate = new CommandTester(new GenerateCommand());
        self::assertSame(Command::SUCCESS, $generate->execute($options));
        self::assertStringContainsString('[warning] openapi.version', $generate->getDisplay());
    }

    public function testDiagnosticOrderingDeduplicationAndUnknownProvenance(): void
    {
        $diagnostics = new DiagnosticBag();
        $diagnostic = new Diagnostic('test.code', 'error', '/a', 'Invalid.', 'Fix it.');
        $diagnostics->add($diagnostic);
        $diagnostics->add($diagnostic);

        self::assertCount(1, $diagnostics->all());
        self::assertStringContainsString('original source unavailable', (string) $diagnostic);
    }

    /** @return array<string, mixed> */
    private function spec(): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'Test', 'version' => '1.0'],
            'components' => ['schemas' => ['Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]]],
            'paths' => ['/pets' => ['get' => ['operationId' => 'listPets', 'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']]]]]]]],
        ];
    }

    /** @param array<string, mixed> $spec */
    private function writeSpec(array $spec): void
    {
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'spec.json', json_encode($spec, JSON_THROW_ON_ERROR));
    }

    private function writeConfig(): void
    {
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'config.php', '<?php
            $config = new \MaxBeckers\OpenApiGenerator\Config\GeneratorConfig();
            $config->specFile = "spec.json";
            $config->outputDir = "output";
            return $config;
        ');
    }

    private function removeDirectory(string $directory): void
    {
        foreach (new \FilesystemIterator($directory) as $entry) {
            if ($entry->isDir()) {
                $this->removeDirectory($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
