<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Unit\Generator;

use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Generator\TemplateEngine;
use PHPUnit\Framework\TestCase;

class TemplateEngineTest extends TestCase
{
    private string $templateBasePath;

    protected function setUp(): void
    {
        $this->templateBasePath = sys_get_temp_dir() . '/openapi-template-engine-' . uniqid('', true);
        mkdir($this->templateBasePath . '/php82', 0777, true);
        file_put_contents(
            $this->templateBasePath . '/php82/hello.twig',
            'Hello {{ name }}!'
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->templateBasePath);
    }

    /**
     * @dataProvider fallbackVersionProvider
     */
    public function testFallsBackToPhp82TemplatesForNewerPhpVersions(string $phpVersion): void
    {
        $engine = new TemplateEngine($this->templateBasePath, $phpVersion);

        self::assertSame('Hello world!', $engine->render('hello.twig', ['name' => 'world']));
    }

    public function testUsesRunningPhpVersionWithoutAnOverride(): void
    {
        $config = new GeneratorConfig();
        $engine = new TemplateEngine($this->templateBasePath, $config->phpVersion);

        self::assertSame('Hello world!', $engine->render('hello.twig', ['name' => 'world']));
    }

    /**
     * @dataProvider templateSelectionProvider
     */
    public function testSelectsTemplatesByMajorAndMinorVersion(string $phpVersion, string $expected): void
    {
        foreach (['83', '85'] as $version) {
            $dir = $this->templateBasePath . '/php' . $version;
            mkdir($dir);
            file_put_contents($dir . '/hello.twig', 'PHP ' . $version);
        }

        $engine = new TemplateEngine($this->templateBasePath, $phpVersion);

        self::assertSame($expected, $engine->render('hello.twig', ['name' => 'world']));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function templateSelectionProvider(): array
    {
        return [
            'exact match' => ['8.2', 'Hello world!'],
            'exact match with patch' => ['8.3.22', 'PHP 83'],
            'nearest lower version, not higher' => ['8.4.22', 'PHP 83'],
            'newest exact match' => ['8.5', 'PHP 85'],
            'future version' => ['9.0', 'PHP 85'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fallbackVersionProvider(): array
    {
        return [
            'php 8.2 patch version' => ['8.2.30'],
            'php 8.3' => ['8.3'],
            'php 8.4' => ['8.4'],
            'php 8.4 patch version' => ['8.4.22'],
            'php 8.5' => ['8.5'],
            'php 9.0' => ['9.0'],
        ];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
