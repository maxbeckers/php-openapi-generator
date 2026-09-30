<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Integration;

use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;
use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Config\ValidationStrategy;
use MaxBeckers\OpenApiGenerator\Loader\OpenApiLoader;
use MaxBeckers\OpenApiGenerator\Tests\Support\GeneratedCodeTestSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SpecEdgeCaseCoverageTest extends TestCase
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

    public function testOpenApi31ModelsAliasesOperationsAndResponsesGenerateAndRun(): void
    {
        $config = $this->makeConfig();
        $config->validationStrategy = ValidationStrategy::NativeMethod;
        $output = $this->generateCode($config, self::FIXTURES_DIR, false);
        $this->assertAllGeneratedFilesLint($output);
        $this->registerAutoloader($config->modelNamespace, 'Model');
        $this->registerAutoloader($config->apiNamespace, 'Api');

        self::assertFileExists($output . '/Model/Record.php');
        self::assertFileExists($output . '/Model/RecordMap.php');
        self::assertFileExists($output . '/Model/Combined.php');
        self::assertFileExists($output . '/Model/Schema9Events.php');
        self::assertFileExists($output . '/Model/Class_.php');
        self::assertFileDoesNotExist($output . '/Api/AuditApiClient.php');
        self::assertFileDoesNotExist($output . '/Model/RecordId.php');
        self::assertFileDoesNotExist($output . '/Model/RecordIds.php');

        $recordSource = (string) file_get_contents($output . '/Model/Record.php');
        self::assertStringContainsString('?string $note', $recordSource);
        self::assertStringContainsString('float $score', $recordSource);
        self::assertStringContainsString('string $fixed', $recordSource);
        self::assertStringContainsString('RecordProfile $profile', $recordSource);
        $profileSource = (string) file_get_contents($output . '/Model/RecordProfile.php');
        self::assertStringContainsString('RecordProfileAliases::fromArray($item)', $profileSource);
        self::assertStringContainsString('$this->score <= 1', $recordSource);
        self::assertStringContainsString('$this->score >= 10', $recordSource);

        $recordClass = $config->modelNamespace . '\\Record';
        $record = $recordClass::fromArray([
            'id' => 'r-1',
            'createdAt' => '2026-04-05',
            'status' => 'open',
            'note' => null,
            'score' => 5.5,
            'fixed' => 'locked',
            'profile' => ['aliases' => [['value' => 'primary']]],
        ]);
        self::assertSame('2026-04-05', $record->createdAt->format('Y-m-d'));
        self::assertSame('open', $record->status->value);
        self::assertSame('locked', $record->fixed);
        self::assertSame('primary', $record->profile->aliases[0]->value);
        self::assertSame('locked', $record->toArray()['fixed']);
        self::assertSame([], $record->validate());
        self::assertNotEmpty($recordClass::fromArray([
            'id' => 'r-2',
            'createdAt' => '2026-04-05',
            'status' => 'open',
            'score' => 5.5,
            'fixed' => 'changed',
        ])->validate());
        self::assertNull($record->note);

        $mapClass = $config->modelNamespace . '\\RecordMap';
        self::assertSame(
            ['attempts' => 3],
            $mapClass::fromArray(['attempts' => 3])->toArray(),
        );

        $combinedClass = $config->modelNamespace . '\\Combined';
        $combined = $combinedClass::fromArray([
            'baseName' => 'base',
            'extraName' => 'extra',
            'combinedName' => 'combined',
        ]);
        self::assertSame('extra', $combined->extraName);
        self::assertSame('combined', $combined->combinedName);

        $schema9Class = $config->modelNamespace . '\\Schema9Events';
        self::assertSame('legacy', $schema9Class::fromArray(['9-code' => 'legacy'])->value9Code);
        $reservedClass = $config->modelNamespace . '\\Class_';
        $reserved = $reservedClass::fromArray(['class' => 'reserved', 'legacy-name' => 'special']);
        self::assertSame('reserved', $reserved->class_);
        self::assertSame('special', $reserved->legacyName);

        $recordsApi = (string) file_get_contents($output . '/Api/RecordsApiClient.php');
        self::assertStringContainsString('function updateRecord(', $recordsApi);
        self::assertStringContainsString('function getRecordsRecordId(', $recordsApi);
        self::assertStringContainsString('function updateRecord(string $recordId, ?int $page, RecordInput $body): Record', $recordsApi);
        self::assertStringContainsString('?RecordState $state', $recordsApi);
        self::assertStringContainsString('?NullableState $nullableState', $recordsApi);
        self::assertStringContainsString('Priority $priority', $recordsApi);
        self::assertStringContainsString('$default_', $recordsApi);
        self::assertStringContainsString('@deprecated', $recordsApi);
        self::assertStringContainsString('@param ?string $default_ (deprecated: "default")', $recordsApi);
        self::assertStringContainsString('function getCombined(', $recordsApi);
        self::assertStringContainsString('function match_(', $recordsApi);
        self::assertStringContainsString('function uploadJson(', $recordsApi);
        self::assertStringContainsString(': ?Record', $recordsApi);

        $defaultApi = (string) file_get_contents($output . '/Api/DefaultApiClient.php');
        self::assertStringContainsString('function getUnlabelled()', $defaultApi);
        self::assertStringContainsString(': string', $defaultApi);

        $aliasesApi = (string) file_get_contents($output . '/Api/AliasesApiClient.php');
        self::assertStringContainsString('function listRecordIds()', $aliasesApi);
        self::assertStringContainsString('): array', $aliasesApi);

        $clientNamespace = $config->apiNamespace;
        $clientClass = $clientNamespace . '\\RecordsApiClient';
        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = [$method, $url];

            return new MockResponse(
                '{"id":"r-1","createdAt":"2026-04-05","status":"open","fixed":"locked"}',
                ['http_code' => 200],
            );
        });
        $apiClient = new $clientClass($httpClient, 'https://edge.example.test');
        $enumClass = $config->modelNamespace . '\\RecordState';
        $nullableEnumClass = $config->modelNamespace . '\\NullableState';
        $priorityClass = $config->modelNamespace . '\\Priority';
        $apiClient->getRecordsRecordId(
            'r-1',
            3,
            $enumClass::fromArray('open'),
            $nullableEnumClass::fromArray('published'),
            $priorityClass::fromArray(2),
            'match',
        );
        self::assertSame('GET', $requests[0][0]);
        self::assertSame(
            'https://edge.example.test/records/r-1?page=3&state=open&nullableState=published&priority=2&default=match',
            $requests[0][1],
        );

        $defaultClass = $clientNamespace . '\\DefaultApiClient';
        $defaultClient = new $defaultClass(
            new MockHttpClient(static fn (): MockResponse => new MockResponse('"r-99"')),
            'https://edge.example.test',
        );
        self::assertSame('r-99', $defaultClient->getUnlabelled());

        $aliasesClass = $clientNamespace . '\\AliasesApiClient';
        $aliasesClient = new $aliasesClass(new MockHttpClient([
            new MockResponse('["r-1","r-2"]'),
            new MockResponse('{"attempts":3}'),
        ]), 'https://edge.example.test');
        self::assertSame(['r-1', 'r-2'], $aliasesClient->listRecordIds());
        self::assertSame(['attempts' => 3], $aliasesClient->getRecordMap()->toArray());
    }

    public function testNamingConfigurationAndPhpDocSwitchAreApplied(): void
    {
        $config = $this->makeConfig();
        $config->classPrefix = 'Edge';
        $config->enumSuffix = 'Enum';
        $config->interfaceSuffix = 'Contract';
        $config->generatePhpDoc = false;
        $config->sortPropertiesByRequired = false;
        $config->dateClass = \DateTime::class;
        $output = $this->generateCode($config, self::FIXTURES_DIR, false);
        $this->assertAllGeneratedFilesLint($output);

        self::assertFileExists($output . '/Model/EdgeRecord.php');
        self::assertFileExists($output . '/Model/EdgeRecordStateEnum.php');
        self::assertFileExists($output . '/Model/EdgeChoice.php');
        $record = (string) file_get_contents($output . '/Model/EdgeRecord.php');
        self::assertStringContainsString('\\DateTime $createdAt', $record);
        self::assertStringNotContainsString('Optional note', $record);
        $sortCheck = (string) file_get_contents($output . '/Model/EdgeSortCheck.php');
        self::assertLessThan(
            strpos($sortCheck, '$requiredSecond'),
            strpos($sortCheck, '$optionalFirst'),
            'sortPropertiesByRequired=false should preserve schema property order.',
        );
    }

    public function testConfiguredReservedWordSuffixIsUsedInGeneratedPhp(): void
    {
        $config = $this->makeConfig();
        $config->reservedWordSuffix = 'Value';
        $output = $this->generateCode($config, self::FIXTURES_DIR, false);
        $this->assertAllGeneratedFilesLint($output);

        self::assertFileExists($output . '/Model/ClassValue.php');
        $reserved = (string) file_get_contents($output . '/Model/ClassValue.php');
        self::assertStringContainsString('$classValue', $reserved);
        $records = (string) file_get_contents($output . '/Api/RecordsApiClient.php');
        self::assertStringContainsString('$defaultValue', $records);
        self::assertStringContainsString('function matchValue(', $records);
    }

    public function testOperationParameterOverridesPathLevelParameter(): void
    {
        $config = $this->makeConfig();
        $config->generationTarget = GenerationTarget::Server;
        $config->validationStrategy = ValidationStrategy::NativeMethod;
        $config->validateServerRequest = true;
        $output = $this->generateCode($config, self::FIXTURES_DIR, false);
        $this->assertAllGeneratedFilesLint($output);

        $interface = (string) file_get_contents($output . '/Api/RecordsApiInterface.php');
        self::assertStringContainsString('?int $page', $interface);
        self::assertStringContainsString('@param ?string $default_ (deprecated: "default")', $interface);
        self::assertStringContainsString('@deprecated', $interface);
        $spec = (new OpenApiLoader())->loadFile(self::FIXTURES_DIR . '/spec-edge-cases.yaml');
        $parameters = array_values(array_filter(
            $spec->paths['/records/{recordId}']->get->parameters,
            static fn ($parameter): bool => $parameter->name === 'page',
        ));
        self::assertCount(1, $parameters);
        self::assertSame(2, $parameters[0]->schema->minimum);
    }

    public function testNormalizedSchemaNameCollisionsFailWithAnActionableMessage(): void
    {
        $config = $this->makeConfig('name-collisions.yaml');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('both normalize to PHP name "UserId"');
        $this->generateCode($config, self::FIXTURES_DIR, false);
    }

    public function testNormalizedPropertyNameCollisionsFailWithAnActionableMessage(): void
    {
        $config = $this->makeConfig('property-name-collisions.yaml');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('both normalize to PHP name "$recordId" in schema "CollisionRecord"');
        $this->generateCode($config, self::FIXTURES_DIR, false);
    }

    public function testNormalizedOperationNameCollisionsFailWithAnActionableMessage(): void
    {
        $config = $this->makeConfig('operation-name-collisions.yaml');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('both normalize to PHP method "getRecord"');
        $this->generateCode($config, self::FIXTURES_DIR, false);
    }

    /**
     * @return iterable<string, array{string, GenerationTarget}>
     */
    public static function unsupportedRequestContentTypes(): iterable
    {
        foreach ([
            'text/plain' => '/upload',
            'form-urlencoded' => '/upload/form',
            'multipart' => '/upload/multipart',
            'octet-stream' => '/upload/octet-stream',
        ] as $contentType => $path) {
            foreach ([GenerationTarget::Server, GenerationTarget::Client] as $target) {
                yield $contentType . ' ' . $target->value => [$path, $target];
            }
        }
    }

    #[DataProvider('unsupportedRequestContentTypes')]
    public function testNonJsonRequestBodiesAreRejectedInsteadOfMisgenerated(
        string $path,
        GenerationTarget $target,
    ): void {
        $config = $this->makeConfig('unsupported-request-content.yaml');
        $config->generationTarget = $target;
        $config->includePaths = [$path];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('only application/json is supported');
        $this->generateCode($config, self::FIXTURES_DIR, false);
    }

    private function makeConfig(string $specFile = 'spec-edge-cases.yaml'): GeneratorConfig
    {
        $suffix = bin2hex(random_bytes(5));
        $config = new GeneratorConfig();
        $config->specFile = $specFile;
        $config->modelNamespace = 'Generated\\Edge' . $suffix . '\\Model';
        $config->modelOutputDir = 'Model';
        $config->apiNamespace = 'Generated\\Edge' . $suffix . '\\Api';
        $config->apiOutputDir = 'Api';
        $config->generationTarget = GenerationTarget::Client;
        $config->generateFromArray = true;
        $config->generateToArray = true;
        $config->phpVersion = '8.2';

        return $config;
    }

    private function registerAutoloader(string $namespace, string $directory): void
    {
        $prefix = $namespace . '\\';
        $baseDirectory = $this->generatedCodeOutputDir . DIRECTORY_SEPARATOR . $directory;
        $loader = static function (string $class) use ($prefix, $baseDirectory): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $path = $baseDirectory . DIRECTORY_SEPARATOR
                . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        };
        spl_autoload_register($loader);
        $this->autoloaders[] = $loader;
    }
}
