<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Integration;

use Composer\Composer;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use MaxBeckers\OpenApiGenerator\Command\CleanCommand;
use MaxBeckers\OpenApiGenerator\Command\GenerateCommand;
use MaxBeckers\OpenApiGenerator\FileWriter\FileWriter;
use MaxBeckers\OpenApiGenerator\Loader\ConfigFileLoader;
use MaxBeckers\OpenApiGenerator\Plugin\ComposerPlugin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandPluginFileWritingTest extends TestCase
{
    private string $tempDir;
    private string $originalWorkingDirectory;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'openapi-command-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->tempDir, 0777, true));
        $this->originalWorkingDirectory = (string) getcwd();
    }

    protected function tearDown(): void
    {
        chdir($this->originalWorkingDirectory);
        $this->removeDirectory($this->tempDir);
    }

    public function testGenerateCommandDiscoversConfigAndResolvesRelativePaths(): void
    {
        $this->writeConfig();
        chdir($this->tempDir);

        $tester = new CommandTester(new GenerateCommand());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Done.', $tester->getDisplay());
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'generated' . DIRECTORY_SEPARATOR . 'Model' . DIRECTORY_SEPARATOR . 'Order.php');
    }

    public function testGenerateCommandAppliesTargetFrameworkAndHttpClientOverrides(): void
    {
        $this->writeConfig();
        $tester = new CommandTester(new GenerateCommand());

        $exitCode = $tester->execute([
            '--config' => $this->configPath(),
            '--target' => 'client',
            '--framework' => 'laravel',
            '--http-client' => 'guzzle',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $clientFiles = glob($this->tempDir . DIRECTORY_SEPARATOR . 'generated' . DIRECTORY_SEPARATOR . 'Api' . DIRECTORY_SEPARATOR . '*ApiClient.php');
        self::assertIsArray($clientFiles);
        self::assertNotEmpty($clientFiles);
        $client = file_get_contents($clientFiles[0]);
        self::assertNotFalse($client);
        self::assertStringContainsString('GuzzleHttp\\ClientInterface', $client);
    }

    /**
     * @dataProvider invalidOverrideProvider
     *
     * @param array<string, string> $options
     */
    public function testGenerateCommandRejectsInvalidOverrides(array $options, string $message): void
    {
        $this->writeConfig();
        $tester = new CommandTester(new GenerateCommand());

        $exitCode = $tester->execute(['--config' => $this->configPath()] + $options);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString($message, $tester->getDisplay());
        self::assertDirectoryDoesNotExist($this->tempDir . DIRECTORY_SEPARATOR . 'generated');
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidOverrideProvider(): iterable
    {
        yield 'target' => [['--target' => 'model'], 'Invalid --target'];
        yield 'framework' => [['--framework' => 'cakephp'], 'Invalid --framework'];
        yield 'http client' => [['--http-client' => 'curl'], 'Invalid --http-client'];
    }

    public function testGenerateCommandReportsMissingConfigAndSpecFiles(): void
    {
        chdir($this->tempDir);
        $missingConfig = new CommandTester(new GenerateCommand());
        self::assertSame(Command::FAILURE, $missingConfig->execute([]));
        self::assertStringContainsString('No php-openapi-generator.php config file found', $missingConfig->getDisplay());

        $this->writeConfig('missing.yaml');
        $missingSpec = new CommandTester(new GenerateCommand());
        self::assertSame(Command::FAILURE, $missingSpec->execute(['--config' => $this->configPath()]));
        self::assertStringContainsString('Generation failed:', $missingSpec->getDisplay());
    }

    public function testCleanCommandOnlyCleansConfiguredOutputAndHandlesMissingDirectory(): void
    {
        $this->writeConfig();
        $outputDir = $this->tempDir . DIRECTORY_SEPARATOR . 'generated';
        self::assertTrue(mkdir($outputDir . DIRECTORY_SEPARATOR . 'nested', 0777, true));
        file_put_contents($outputDir . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'generated.php', 'generated');
        $outsideFile = $this->tempDir . DIRECTORY_SEPARATOR . 'keep.txt';
        file_put_contents($outsideFile, 'keep');

        $tester = new CommandTester(new CleanCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['--config' => $this->configPath()]));
        self::assertFileDoesNotExist($outputDir . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'generated.php');
        self::assertFileExists($outsideFile);

        self::assertSame(Command::SUCCESS, $tester->execute(['--config' => $this->configPath()]));
    }

    public function testConfigFileLoaderValidatesFilesAndReturnValues(): void
    {
        $loader = new ConfigFileLoader();
        $this->writeConfig();

        $config = $loader->load($this->configPath());
        self::assertSame($this->tempDir . DIRECTORY_SEPARATOR . 'spec.yaml', $config->specFile);
        self::assertSame($this->tempDir . DIRECTORY_SEPARATOR . 'generated', $config->outputDir);
        self::assertSame($this->configPath(), $loader->findConfigFile($this->tempDir));

        $invalidConfig = $this->tempDir . DIRECTORY_SEPARATOR . 'invalid.php';
        file_put_contents($invalidConfig, '<?php return [];');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must return an instance');
        $loader->load($invalidConfig);
    }

    public function testConfigFileLoaderReportsMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Config file not found');
        (new ConfigFileLoader())->load($this->tempDir . DIRECTORY_SEPARATOR . 'absent.php');
    }

    public function testFileWriterCreatesDirectoriesOverwritesAndKeepsStaleFiles(): void
    {
        $writer = new FileWriter();
        $outputDir = $this->tempDir . DIRECTORY_SEPARATOR . 'output';
        $staleFile = $outputDir . DIRECTORY_SEPARATOR . 'stale.php';

        $writer->writeAll($outputDir, [
            'nested/Model.php' => 'first',
            'stale.php' => 'stale',
        ]);
        $writer->writeAll($outputDir, ['nested/Model.php' => 'second']);

        self::assertSame('second', file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'Model.php'));
        self::assertFileExists($staleFile);
    }

    public function testFileWriterRejectsPathsOutsideOutputDirectoryAndReportsFailures(): void
    {
        $writer = new FileWriter();
        $outsideFile = $this->tempDir . DIRECTORY_SEPARATOR . 'outside.php';

        try {
            $writer->writeAll($this->tempDir . DIRECTORY_SEPARATOR . 'output', ['../outside.php' => 'unsafe']);
            self::fail('Parent traversal should have been rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('must stay within the output directory', $e->getMessage());
        }
        self::assertFileDoesNotExist($outsideFile);

        $directoryPath = $this->tempDir . DIRECTORY_SEPARATOR . 'existing-directory';
        self::assertTrue(mkdir($directoryPath));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to write file');
        $writer->write($directoryPath, 'content');
    }

    public function testComposerPluginSubscribesToPostAutoloadDumpAndGenerates(): void
    {
        self::assertSame(
            [ScriptEvents::POST_AUTOLOAD_DUMP => 'onPostAutoloadDump'],
            ComposerPlugin::getSubscribedEvents(),
        );
        $this->writeConfig();
        [$plugin, $event, $io] = $this->createComposerPlugin();
        $io->expects(self::exactly(2))->method('write');
        $io->expects(self::never())->method('writeError');

        $plugin->onPostAutoloadDump($event);

        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'generated' . DIRECTORY_SEPARATOR . 'Model' . DIRECTORY_SEPARATOR . 'Order.php');
    }

    /**
     * @dataProvider disabledPluginProvider
     */
    public function testComposerPluginRespectsDisableFlags(bool $autoGenerate, bool $addPlugin): void
    {
        $this->writeConfig(autoGenerate: $autoGenerate, addPlugin: $addPlugin);
        [$plugin, $event, $io] = $this->createComposerPlugin();
        $io->expects(self::never())->method('write');
        $io->expects(self::never())->method('writeError');

        $plugin->onPostAutoloadDump($event);

        self::assertDirectoryDoesNotExist($this->tempDir . DIRECTORY_SEPARATOR . 'generated');
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function disabledPluginProvider(): iterable
    {
        yield 'auto generation disabled' => [false, true];
        yield 'plugin disabled' => [true, false];
    }

    public function testComposerPluginSilentlyIgnoresMissingConfigAndWarnsForInvalidConfig(): void
    {
        [$plugin, $event, $io] = $this->createComposerPlugin();
        $io->expects(self::never())->method('write');
        $io->expects(self::never())->method('writeError');
        $plugin->onPostAutoloadDump($event);

        file_put_contents($this->configPath(), '<?php return [];');
        [$invalidPlugin, $invalidEvent, $invalidIo] = $this->createComposerPlugin();
        $invalidIo->expects(self::never())->method('write');
        $invalidIo->expects(self::once())
            ->method('writeError')
            ->with(self::stringContains('Failed to load config'));
        $invalidPlugin->onPostAutoloadDump($invalidEvent);
    }

    private function writeConfig(
        string $specFile = 'spec.yaml',
        bool $autoGenerate = true,
        bool $addPlugin = true,
    ): void {
        if ($specFile === 'spec.yaml') {
            copy(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'feature-test.yaml', $this->tempDir . DIRECTORY_SEPARATOR . $specFile);
        }

        $config = sprintf(
            <<<'PHP'
<?php

use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;

return (new GeneratorConfig())
    ->setSpecFile(%s)
    ->setOutputDir('generated')
    ->setModelNamespace('Generated\Model')
    ->setApiNamespace('Generated\Api')
    ->setApiOutputDir('Api')
    ->setAutoGenerate(%s)
    ->setAddPlugin(%s);
PHP,
            var_export($specFile, true),
            $autoGenerate ? 'true' : 'false',
            $addPlugin ? 'true' : 'false',
        );
        file_put_contents($this->configPath(), $config);
    }

    /**
     * @return array{ComposerPlugin, Event, IOInterface&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function createComposerPlugin(): array
    {
        $config = $this->createMock(Config::class);
        $config->method('get')
            ->with('vendor-dir')
            ->willReturn($this->tempDir . DIRECTORY_SEPARATOR . 'vendor');
        $composer = $this->createMock(Composer::class);
        $composer->method('getConfig')->willReturn($config);
        $io = $this->createMock(IOInterface::class);
        $plugin = new ComposerPlugin();
        $plugin->activate($composer, $io);

        return [$plugin, new Event(ScriptEvents::POST_AUTOLOAD_DUMP, $composer, $io), $io];
    }

    private function configPath(): string
    {
        return $this->tempDir . DIRECTORY_SEPARATOR . 'php-openapi-generator.php';
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
