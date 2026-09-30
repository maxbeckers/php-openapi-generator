<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Quality;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class GeneratedCodeQualityTest extends TestCase
{
    #[Group('quality')]
    public function testGeneratedCodePassesStaticAnalysisAndFormatting(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $phpstan = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR
            . 'phpstan' . DIRECTORY_SEPARATOR . 'phpstan' . DIRECTORY_SEPARATOR . 'phpstan.phar';
        $fixer = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'php-cs-fixer';

        if (!is_file($phpstan) || !is_file($fixer)) {
            self::markTestSkipped('Install PHPStan and PHP-CS-Fixer to run generated-code quality checks.');
        }

        $command = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($projectRoot . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Quality'
                . DIRECTORY_SEPARATOR . 'check-generated.php');
        exec($command . ' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
    }
}
