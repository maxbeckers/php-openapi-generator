<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Unit\Config;

use MaxBeckers\OpenApiGenerator\Config\FrameworkTarget;
use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;
use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Config\HttpClientAdapter;
use MaxBeckers\OpenApiGenerator\Config\ValidationStrategy;
use PHPUnit\Framework\TestCase;

final class GeneratorConfigTest extends TestCase
{
    public function testFrameworkVersionProperty(): void
    {
        $config = new GeneratorConfig();

        // Default should be null
        $this->assertNull($config->frameworkVersion);

        // Should be settable
        $config->frameworkVersion = '8.0';
        $this->assertEquals('8.0', $config->frameworkVersion);

        // Should support setter method chaining
        $result = $config->setFrameworkVersion('9.0');
        $this->assertSame($config, $result);
        $this->assertEquals('9.0', $config->frameworkVersion);

        // Should support null value
        $config->setFrameworkVersion(null);
        $this->assertNull($config->frameworkVersion);
    }

    public function testHttpClientVersionProperty(): void
    {
        $config = new GeneratorConfig();

        // Default should be null
        $this->assertNull($config->httpClientVersion);

        // Should be settable
        $config->httpClientVersion = '7.4';
        $this->assertEquals('7.4', $config->httpClientVersion);

        // Should support setter method chaining
        $result = $config->setHttpClientVersion('7.8');
        $this->assertSame($config, $result);
        $this->assertEquals('7.8', $config->httpClientVersion);

        // Should support null value
        $config->setHttpClientVersion(null);
        $this->assertNull($config->httpClientVersion);
    }

    public function testFrameworkAndHttpClientVersionsWithFluentInterface(): void
    {
        $config = (new GeneratorConfig())
            ->setFrameworkTarget(FrameworkTarget::Laravel)
            ->setFrameworkVersion('11.0')
            ->setHttpClient(HttpClientAdapter::Guzzle)
            ->setHttpClientVersion('7.8')
            ->setGenerationTarget(GenerationTarget::Client);

        $this->assertEquals(FrameworkTarget::Laravel, $config->frameworkTarget);
        $this->assertEquals('11.0', $config->frameworkVersion);
        $this->assertEquals(HttpClientAdapter::Guzzle, $config->httpClient);
        $this->assertEquals('7.8', $config->httpClientVersion);
    }

    public function testSymfonyVersioningExample(): void
    {
        $config = new GeneratorConfig();
        $config->setFrameworkTarget(FrameworkTarget::Symfony)
            ->setFrameworkVersion('8.1')
            ->setValidationStrategy(ValidationStrategy::SymfonyConstraints);

        $this->assertEquals(FrameworkTarget::Symfony, $config->frameworkTarget);
        $this->assertEquals('8.1', $config->frameworkVersion);
        $this->assertEquals(ValidationStrategy::SymfonyConstraints, $config->validationStrategy);
    }

    public function testLaravelVersioningExample(): void
    {
        $config = new GeneratorConfig();
        $config->setFrameworkTarget(FrameworkTarget::Laravel)
            ->setFrameworkVersion('10.0')
            ->setValidationStrategy(ValidationStrategy::LaravelValidation);

        $this->assertEquals(FrameworkTarget::Laravel, $config->frameworkTarget);
        $this->assertEquals('10.0', $config->frameworkVersion);
        $this->assertEquals(ValidationStrategy::LaravelValidation, $config->validationStrategy);
    }
}
