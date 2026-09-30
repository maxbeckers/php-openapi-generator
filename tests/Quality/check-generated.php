<?php

declare(strict_types=1);

use MaxBeckers\OpenApiGenerator\Config\FrameworkTarget;
use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;
use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Config\HttpClientAdapter;
use MaxBeckers\OpenApiGenerator\Config\ValidationStrategy;
use MaxBeckers\OpenApiGenerator\FileWriter\FileWriter;
use MaxBeckers\OpenApiGenerator\Loader\OpenApiLoader;
use MaxBeckers\OpenApiGenerator\Service\OpenApiService;

require_once __DIR__ . '/../../vendor/autoload.php';

$projectRoot = dirname(__DIR__, 2);
$outputRoot = $projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'generated-quality';

removeDirectory($outputRoot);

$service = new OpenApiService(new OpenApiLoader(), new FileWriter());
$generatedDirectories = [];

foreach (FrameworkTarget::cases() as $framework) {
    foreach (ValidationStrategy::cases() as $validation) {
        $case = 'server-' . $framework->value . '-' . $validation->value;
        $generatedDirectories[] = generateCombination(
            $service,
            $projectRoot,
            $outputRoot,
            $case,
            GenerationTarget::Server,
            $framework,
            null,
            $validation,
        );
    }
}

foreach (HttpClientAdapter::cases() as $adapter) {
    foreach (ValidationStrategy::cases() as $validation) {
        $case = 'client-' . $adapter->value . '-' . $validation->value;
        $generatedDirectories[] = generateCombination(
            $service,
            $projectRoot,
            $outputRoot,
            $case,
            GenerationTarget::Client,
            null,
            $adapter,
            $validation,
        );
    }
}

// OpenAPI 3.1, aliases, nested inline objects, compositions, enum parameters,
// default/empty responses, and renamed PHP identifiers.
$generatedDirectories[] = generateCombination(
    $service,
    $projectRoot,
    $outputRoot,
    'edge-server-symfony-native',
    GenerationTarget::Server,
    FrameworkTarget::Symfony,
    null,
    ValidationStrategy::NativeMethod,
    'spec-edge-cases.yaml',
);
$generatedDirectories[] = generateCombination(
    $service,
    $projectRoot,
    $outputRoot,
    'edge-client-symfony-native',
    GenerationTarget::Client,
    null,
    HttpClientAdapter::SymfonyHttpClient,
    ValidationStrategy::NativeMethod,
    'spec-edge-cases.yaml',
);

// Client runtime fixture: query styles, array/enum parameters, nullable and union success responses.
foreach (HttpClientAdapter::cases() as $adapter) {
    foreach ([ValidationStrategy::None, ValidationStrategy::NativeMethod] as $validation) {
        $case = 'client-runtime-' . $adapter->value . '-' . $validation->value;
        $generatedDirectories[] = generateCombination(
            $service,
            $projectRoot,
            $outputRoot,
            $case,
            GenerationTarget::Client,
            null,
            $adapter,
            $validation,
            'client-runtime.yaml',
        );
    }

    // Typed error exceptions on top of security schemes and date parameters.
    $generatedDirectories[] = generateCombination(
        $service,
        $projectRoot,
        $outputRoot,
        'client-runtime-' . $adapter->value . '-typed_errors',
        GenerationTarget::Client,
        null,
        $adapter,
        ValidationStrategy::None,
        'client-runtime.yaml',
        true,
    );
}

// Server controllers for the runtime fixture: parameter parsing, body parsing, request validation, status codes.
foreach ([FrameworkTarget::Symfony, FrameworkTarget::Laravel] as $framework) {
    $validations = [ValidationStrategy::None, ValidationStrategy::NativeMethod, ValidationStrategy::SymfonyConstraints];
    if ($framework === FrameworkTarget::Laravel) {
        $validations[] = ValidationStrategy::LaravelValidation;
    }
    foreach ($validations as $validation) {
        $generatedDirectories[] = generateCombination(
            $service,
            $projectRoot,
            $outputRoot,
            'server-runtime-' . $framework->value . '-' . $validation->value,
            GenerationTarget::Server,
            $framework,
            null,
            $validation,
            'client-runtime.yaml',
        );
    }
}

$phpstan = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR
    . 'phpstan' . DIRECTORY_SEPARATOR . 'phpstan' . DIRECTORY_SEPARATOR . 'phpstan.phar';
$fixer = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin'
    . DIRECTORY_SEPARATOR . 'php-cs-fixer';

if (!is_file($phpstan) || !is_file($fixer)) {
    fwrite(STDERR, "Generated-code quality checks require PHPStan and PHP-CS-Fixer in vendor/.\n");
    exit(1);
}

$phpstanCommand = [PHP_BINARY, $phpstan, 'analyse', '--configuration=' . __DIR__ . '/phpstan.generated.neon', '--memory-limit=1G'];
$phpstanCommand = array_merge($phpstanCommand, $generatedDirectories, ['--no-progress', '--error-format=table']);
if (runCommand($phpstanCommand, 'PHPStan') !== 0) {
    printTemplateMap($generatedDirectories);
    exit(1);
}

$fixerCommand = [PHP_BINARY, $fixer, 'fix', '--config=' . __DIR__ . '/.php-cs-fixer.generated.php', '--dry-run', '--diff', '--allow-risky=yes', ...$generatedDirectories];

if (runCommand($fixerCommand, 'PHP-CS-Fixer') !== 0) {
    printTemplateMap($generatedDirectories);
    exit(1);
}

fwrite(STDOUT, 'Generated code passed PHPStan and PHP-CS-Fixer for ' . count($generatedDirectories) . " combinations.\n");
exit(0);

/**
 * @return string generated output directory
 */
function generateCombination(
    OpenApiService $service,
    string $projectRoot,
    string $outputRoot,
    string $case,
    GenerationTarget $target,
    ?FrameworkTarget $framework,
    ?HttpClientAdapter $adapter,
    ValidationStrategy $validation,
    string $specFile = 'feature-test.yaml',
    bool $typedErrorResponses = false,
): string {
    $namespaceSuffix = implode('', array_map('ucfirst', explode('-', $case)));
    $directory = $outputRoot . DIRECTORY_SEPARATOR . $case;
    $config = new GeneratorConfig();
    $config->specFile = $specFile;
    $config->outputDir = $directory;
    $config->modelNamespace = 'Generated\\Quality\\' . $namespaceSuffix . '\\Model';
    $config->modelOutputDir = 'Model';
    $config->apiNamespace = 'Generated\\Quality\\' . $namespaceSuffix . '\\Api';
    $config->apiOutputDir = 'Api';
    $config->phpVersion = '8.2';
    $config->generationTarget = $target;
    $config->validationStrategy = $validation;
    $config->generateFromArray = true;
    $config->generateToArray = true;
    $config->frameworkTarget = $framework ?? FrameworkTarget::None;
    $config->validateServerRequest = $target === GenerationTarget::Server && $validation !== ValidationStrategy::None;
    $config->validateServerResponse = $target === GenerationTarget::Server && $validation !== ValidationStrategy::None;
    $config->validateClientRequest = $target === GenerationTarget::Client && $validation !== ValidationStrategy::None;
    $config->validateClientResponse = $target === GenerationTarget::Client && $validation !== ValidationStrategy::None;
    $config->typedErrorResponses = $typedErrorResponses;
    if ($adapter !== null) {
        $config->httpClient = $adapter;
    }

    fwrite(STDOUT, "Generating {$case}\n");
    $service->generate($config, $projectRoot . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Fixtures');

    return $directory;
}

/**
 * @param list<string> $command
 */
function runCommand(array $command, string $label): int
{
    fwrite(STDOUT, "\n{$label}: " . implode(' ', array_map('escapeshellarg', $command)) . "\n");
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, "Unable to start {$label}.\n");

        return 1;
    }

    return proc_close($process);
}

function removeDirectory(string $directory): void
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

/**
 * Print the source template for each generated file after a tool failure.
 *
 * @param list<string> $directories
 */
function printTemplateMap(array $directories): void
{
    fwrite(STDERR, "\nGenerated-file template map:\n");

    foreach ($directories as $directory) {
        $case = basename($directory);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($file->getPathname(), strlen($directory) + 1),
            );
            $template = match (true) {
                str_starts_with($relativePath, 'Model/')
                    && str_ends_with($relativePath, 'Interface.php') => 'templates/php82/model/interface.php.twig',
                str_starts_with($relativePath, 'Model/')
                    && preg_match('/^enum\s/m', (string) file_get_contents($file->getPathname())) === 1 => 'templates/php82/model/enum.php.twig',
                str_starts_with($relativePath, 'Model/') => 'templates/php82/model/class.php.twig',
                str_ends_with($relativePath, 'ClientInterface.php') => 'templates/php82/client/interface.php.twig',
                str_ends_with($relativePath, 'ApiCredentials.php') => 'templates/php82/client/credentials.php.twig',
                str_ends_with($relativePath, 'Exception/ApiException.php') => 'templates/php82/client/exception/api-exception.php.twig',
                str_contains($relativePath, 'Exception/') => 'templates/php82/client/exception/status-exception.php.twig',
                str_ends_with($relativePath, 'Client.php') => 'templates/php82/client/class.php.twig + templates/php82/client/operation/'
                        . explode('-', str_replace('client-runtime-', 'client-', $case))[1] . '.php.twig',
                str_ends_with($relativePath, 'Controller.php') => 'templates/php82/server/controller.php.twig + templates/php82/server/controller/'
                        . explode('-', str_replace('server-runtime-', 'server-', $case))[1] . '-action.php.twig',
                str_ends_with($relativePath, 'Routes.php') => 'templates/php82/server/laravel-routes.php.twig',
                str_ends_with($relativePath, 'Interface.php') => 'templates/php82/server/interface.php.twig',
                default => 'unmapped',
            };

            fwrite(STDERR, "  {$case}/{$relativePath} -> {$template}\n");
        }
    }
}
