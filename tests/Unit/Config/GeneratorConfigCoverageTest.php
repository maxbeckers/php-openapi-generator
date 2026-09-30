<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Unit\Config;

use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use PHPUnit\Framework\TestCase;

final class GeneratorConfigCoverageTest extends TestCase
{
    public function testEveryPublicOptionIsConsumedByProductionCodeOrTemplates(): void
    {
        $root = dirname(__DIR__, 3);
        $files = array_merge(
            $this->filesUnder($root . DIRECTORY_SEPARATOR . 'src', 'php'),
            $this->filesUnder($root . DIRECTORY_SEPARATOR . 'templates', 'twig'),
        );
        $configFile = realpath($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'GeneratorConfig.php');
        self::assertNotFalse($configFile);

        $production = '';
        foreach ($files as $file) {
            if (realpath($file) !== $configFile) {
                $content = file_get_contents($file);
                self::assertNotFalse($content);
                $production .= $content;
            }
        }

        $unused = [];
        foreach ((new \ReflectionClass(GeneratorConfig::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = preg_quote($property->getName(), '/');
            if (preg_match('/(?:->|config\.)' . $name . '\b/', $production) !== 1) {
                $unused[] = $property->getName();
            }
        }

        self::assertSame(
            [],
            $unused,
            'Public config options must affect production behavior or be removed.',
        );
    }

    /** @return list<string> */
    private function filesUnder(string $directory, string $extension): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === $extension) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
