<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Unit\Generator;

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

    /**
     * @return array<string, array{0: string}>
     */
    public static function fallbackVersionProvider(): array
    {
        return [
            'php 8.3' => ['8.3'],
            'php 8.4' => ['8.4'],
            'php 8.4 patch version' => ['8.4.22'],
            'php 8.5' => ['8.5'],
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
