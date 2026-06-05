<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Loader;

use InvalidArgumentException;
use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use RuntimeException;

class ConfigFileLoader
{
    private const CONFIG_FILENAME = 'php-openapi-generator.php';

    public function load(string $configFilePath): GeneratorConfig
    {
        if (!file_exists($configFilePath)) {
            throw new InvalidArgumentException(sprintf(
                'Config file not found: %s',
                $configFilePath
            ));
        }

        $config = require $configFilePath;

        if (!$config instanceof GeneratorConfig) {
            throw new RuntimeException(sprintf(
                'Config file must return an instance of %s, got %s',
                GeneratorConfig::class,
                is_object($config) ? $config::class : gettype($config)
            ));
        }

        return $config;
    }

    public function findConfigFile(string $baseDir): ?string
    {
        $path = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . self::CONFIG_FILENAME;

        return file_exists($path) ? $path : null;
    }
}
