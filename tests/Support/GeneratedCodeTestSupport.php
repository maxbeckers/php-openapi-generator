<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Support;

use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\FileWriter\FileWriter;
use MaxBeckers\OpenApiGenerator\Loader\OpenApiLoader;
use MaxBeckers\OpenApiGenerator\Service\OpenApiService;
use PHPUnit\Framework\Assert;

trait GeneratedCodeTestSupport
{
    private string $generatedCodeOutputDir;

    protected function initializeGeneratedCodeTestSupport(): void
    {
        $this->generatedCodeOutputDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'openapi-generator-test-' . bin2hex(random_bytes(8));
    }

    protected function generateCode(
        GeneratorConfig $config,
        string $fixtureDirectory,
        bool $useUniqueNamespaces = true,
    ): string {
        $outputDir = $this->generatedCodeOutputDir;
        $config->outputDir = $outputDir;

        if ($useUniqueNamespaces) {
            $suffix = 'Test' . bin2hex(random_bytes(6));
            $config->modelNamespace = rtrim($config->modelNamespace, '\\') . '\\' . $suffix;
            if ($config->apiNamespace !== '') {
                $config->apiNamespace = rtrim($config->apiNamespace, '\\') . '\\' . $suffix;
            }
        }

        (new OpenApiService(new OpenApiLoader(), new FileWriter()))->generate($config, $fixtureDirectory);

        return $outputDir;
    }

    protected function assertAllGeneratedFilesLint(string $outputDir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($outputDir, \FilesystemIterator::SKIP_DOTS),
        );
        $phpFiles = [];

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $phpFiles[] = $file->getPathname();
        }

        foreach (array_chunk($phpFiles, 8) as $batch) {
            $processes = [];
            foreach ($batch as $path) {
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, '-l', $path],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    options: ['bypass_shell' => true],
                );
                if (!is_resource($process)) {
                    throw new \RuntimeException("Unable to start PHP syntax check for {$path}");
                }

                $processes[] = [$process, $pipes, $path];
            }

            foreach ($processes as [$process, $pipes, $path]) {
                $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = proc_close($process);

                Assert::assertSame(0, $exitCode, $output . "\nFailed to lint {$path}");
            }
        }
    }

    protected function assertGeneratedFileStructure(string $outputDir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($outputDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $content = file_get_contents($path);
            Assert::assertNotFalse($content);
            Assert::assertMatchesRegularExpression(
                '/^<\?php\n\ndeclare\(strict_types=1\);\n\nnamespace [^;]+;/',
                $content,
                "Invalid PHP header in {$path}",
            );
            Assert::assertStringEndsWith("\n", $content, "Missing final newline in {$path}");
            Assert::assertDoesNotMatchRegularExpression('/\n{2,}$/', $content, "Multiple final newlines in {$path}");
            Assert::assertDoesNotMatchRegularExpression('/\r\n|\t|[ \t]+\n/', $content, "Whitespace issue in {$path}");
            Assert::assertDoesNotMatchRegularExpression('/\{\{|\{%|\{#/', $content, "Unrendered Twig marker in {$path}");

            $namespace = [];
            if (preg_match('/^namespace ([^;]+);/m', $content, $matches) === 1) {
                $namespace = explode('\\', trim($matches[1], '\\'));
            }
            $relativeDirectory = substr($file->getPath(), strlen(rtrim($outputDir, '/\\')) + 1);
            $directoryParts = $relativeDirectory === ''
                ? []
                : explode(DIRECTORY_SEPARATOR, $relativeDirectory);
            Assert::assertSame(
                array_slice($namespace, -count($directoryParts)),
                $directoryParts,
                "Generated file path does not match its namespace: {$path}",
            );

            $imports = [];
            preg_match_all('/^use ([^;]+);$/m', $content, $importMatches);
            foreach ($importMatches[1] as $import) {
                $alias = preg_match('/\bas\s+([A-Za-z_][A-Za-z0-9_]*)$/i', trim($import), $aliasMatch) === 1
                    ? $aliasMatch[1]
                    : basename(str_replace('\\', '/', rtrim(trim($import), '\\')));
                $normalizedAlias = strtolower($alias);
                Assert::assertArrayNotHasKey($normalizedAlias, $imports, "Duplicate import {$alias} in {$path}");
                $imports[$normalizedAlias] = $alias;
            }

            $sourceWithoutImports = preg_replace('/^use [^;]+;\R/m', '', $content) ?? $content;
            foreach ($imports as $normalizedAlias => $alias) {
                Assert::assertGreaterThan(
                    0,
                    preg_match_all('/\b' . preg_quote($alias, '/') . '\b/i', $sourceWithoutImports),
                    "Unused import {$alias} in {$path}",
                );
            }
        }
    }

    /**
     * @param string[] $relativeFiles files to require, ordered with interfaces and parent classes first
     */
    protected function loadGenerated(string $outputDir, array $relativeFiles): void
    {
        foreach ($relativeFiles as $relativeFile) {
            $path = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . ltrim($relativeFile, '/\\');
            Assert::assertFileExists($path);
            require_once $path;
        }
    }

    protected function removeGeneratedCodeOutput(): void
    {
        if (!is_dir($this->generatedCodeOutputDir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->generatedCodeOutputDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->generatedCodeOutputDir);
    }
}
