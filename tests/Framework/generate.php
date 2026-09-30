<?php

declare(strict_types=1);

use MaxBeckers\OpenApiGenerator\Config\FrameworkTarget;
use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;
use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Config\ValidationStrategy;
use MaxBeckers\OpenApiGenerator\FileWriter\FileWriter;
use MaxBeckers\OpenApiGenerator\Loader\OpenApiLoader;
use MaxBeckers\OpenApiGenerator\Service\OpenApiService;

/**
 * Generates the real fixture into a per-process temporary directory.
 * The caller's Composer autoloader must already be loaded.
 */
function frameworkFixture(string $target): string
{
    static $directories = [];

    if (isset($directories[$target])) {
        return $directories[$target];
    }

    $framework = FrameworkTarget::from($target);
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'openapi-framework-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700) && !is_dir($directory)) {
        throw new RuntimeException("Cannot create fixture directory: $directory");
    }

    $config = new GeneratorConfig();
    $config->specFile = 'fixture.yaml';
    $config->outputDir = $directory;
    $config->modelNamespace = 'FrameworkFixture\\Model';
    $config->modelOutputDir = 'Model';
    $config->apiNamespace = 'FrameworkFixture\\Api';
    $config->apiOutputDir = 'Api';
    $config->generationTarget = GenerationTarget::Server;
    $config->frameworkTarget = $framework;
    $config->generateFromArray = true;
    $config->generateToArray = true;
    if ($framework === FrameworkTarget::Laravel) {
        $config->validationStrategy = ValidationStrategy::LaravelValidation;
        $config->validateServerRequest = true;
    }

    try {
        (new OpenApiService(new OpenApiLoader(), new FileWriter()))->generate($config, __DIR__);
    } catch (Throwable $exception) {
        removeFrameworkFixture($directory);
        throw $exception;
    }

    spl_autoload_register(static function (string $class) use ($directory): void {
        $prefix = 'FrameworkFixture\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = $directory . DIRECTORY_SEPARATOR
            . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });

    register_shutdown_function(static fn () => removeFrameworkFixture($directory));

    return $directories[$target] = $directory;
}

function removeFrameworkFixture(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}
