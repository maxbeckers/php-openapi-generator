<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Command;

use MaxBeckers\OpenApiGenerator\Config\FrameworkTarget;
use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;
use MaxBeckers\OpenApiGenerator\Config\HttpClientAdapter;
use MaxBeckers\OpenApiGenerator\FileWriter\FileWriter;
use MaxBeckers\OpenApiGenerator\Loader\ConfigFileLoader;
use MaxBeckers\OpenApiGenerator\Loader\OpenApiLoader;
use MaxBeckers\OpenApiGenerator\Service\OpenApiService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;

#[AsCommand(name: 'generate', description: 'Generate PHP model classes from an OpenAPI spec')]
class GenerateCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('strict', null, InputOption::VALUE_NONE, 'Treat preflight compatibility warnings as errors');
        $this->addOption(
            'config',
            'c',
            InputOption::VALUE_OPTIONAL,
            'Path to the php-openapi-generator.php config file',
        );
        $this->addOption(
            'target',
            't',
            InputOption::VALUE_OPTIONAL,
            'Generation target: server or client (overrides config)',
        );
        $this->addOption(
            'http-client',
            null,
            InputOption::VALUE_OPTIONAL,
            'HTTP client adapter for client target: symfony, guzzle, psr18 (overrides config)',
        );
        $this->addOption(
            'framework',
            null,
            InputOption::VALUE_OPTIONAL,
            'Framework target for server: none, symfony, laravel (overrides config)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configLoader = new ConfigFileLoader();

        $configOption = $input->getOption('config');
        if ($configOption !== null) {
            $configFile = $configOption;
        } else {
            $cwd = (string) getcwd();
            $configFile = $configLoader->findConfigFile($cwd);
            if ($configFile === null) {
                $output->writeln('<error>No php-openapi-generator.php config file found in ' . $cwd . '</error>');

                return Command::FAILURE;
            }
        }

        try {
            $config = $configLoader->load($configFile);
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to load config: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        // CLI overrides
        $targetOption = $input->getOption('target');
        if ($targetOption !== null) {
            $target = GenerationTarget::tryFrom($targetOption);
            if ($target === null) {
                $output->writeln('<error>Invalid --target value "' . $targetOption . '". Expected: server or client.</error>');

                return Command::INVALID;
            }
            $config->setGenerationTarget($target);
        }
        $httpClientOption = $input->getOption('http-client');
        if ($httpClientOption !== null) {
            $adapter = HttpClientAdapter::tryFrom($httpClientOption);
            if ($adapter === null) {
                $output->writeln('<error>Invalid --http-client value "' . $httpClientOption . '". Expected: symfony, guzzle, or psr18.</error>');

                return Command::INVALID;
            }
            $config->setHttpClient($adapter);
        }
        $frameworkOption = $input->getOption('framework');
        if ($frameworkOption !== null) {
            $fw = FrameworkTarget::tryFrom($frameworkOption);
            if ($fw === null) {
                $output->writeln('<error>Invalid --framework value "' . $frameworkOption . '". Expected: none, symfony, or laravel.</error>');

                return Command::INVALID;
            }
            $config->setFrameworkTarget($fw);
        }

        if ($input->getOption('strict')) {
            $config->strictSpecValidation = true;
        }

        $validating = $this->isValidationOnly();
        $output->writeln($validating ? '<info>Validating OpenAPI input...</info>' : '<info>Generating OpenAPI models...</info>');

        try {
            $service = new OpenApiService(new OpenApiLoader(), new FileWriter());
            $diagnostics = $validating
                ? $service->validate($config, dirname($configFile))
                : $service->generate($config, dirname($configFile));
            foreach ($diagnostics->all() as $diagnostic) {
                $output->writeln(OutputFormatter::escape((string) $diagnostic));
            }
            if ($diagnostics->hasErrors()) {
                $output->writeln('<error>Validation failed.</error>');

                return Command::FAILURE;
            }
            $output->writeln($validating ? '<info>Validation passed.</info>' : '<info>Done.</info>');
        } catch (\Throwable $e) {
            $output->writeln('<error>' . ($validating ? 'Validation' : 'Generation') . ' failed: ' . OutputFormatter::escape($e->getMessage()) . '</error>');
            if ($output->isVerbose()) {
                $output->writeln($e->getTraceAsString());
            }

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    protected function isValidationOnly(): bool
    {
        return false;
    }
}
