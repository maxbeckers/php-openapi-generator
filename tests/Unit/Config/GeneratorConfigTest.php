<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Unit\Config;

use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use PHPUnit\Framework\TestCase;

class GeneratorConfigTest extends TestCase
{
    public function testPhpVersionDefaultsToRunningPhpVersion(): void
    {
        self::assertSame(PHP_VERSION, (new GeneratorConfig())->phpVersion);
    }

    public function testPhpVersionCanBeOverriddenDirectly(): void
    {
        $config = new GeneratorConfig();
        $config->phpVersion = '8.4.22';

        self::assertSame('8.4.22', $config->phpVersion);
    }

    public function testPhpVersionCanBeOverriddenFluently(): void
    {
        $config = new GeneratorConfig();

        self::assertSame($config, $config->setPhpVersion('9.0'));
        self::assertSame('9.0', $config->phpVersion);
    }
}
