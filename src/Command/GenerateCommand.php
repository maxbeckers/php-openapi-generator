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

#[AsCommand(name: 'generate', description: 'Generate PHP model classes from an OpenAPI spec')]
class GenerateCommand extends Command
{
    protected function configure(): void
    {
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
            'Generation target: model, server, or client (overrides config)',
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
            $config->setGenerationTarget(match ($targetOption) {
                'client' => GenerationTarget::Client,
                'server' => GenerationTarget::Server,
                default  => GenerationTarget::Server,
            });
        }
        $httpClientOption = $input->getOption('http-client');
        if ($httpClientOption !== null) {
            $adapter = HttpClientAdapter::tryFrom($httpClientOption);
            if ($adapter !== null) {
                $config->setHttpClient($adapter);
            }
        }
        $frameworkOption = $input->getOption('framework');
        if ($frameworkOption !== null) {
            $fw = FrameworkTarget::tryFrom($frameworkOption);
            if ($fw !== null) {
                $config->setFrameworkTarget($fw);
            }
        }

        $output->writeln('<info>Generating OpenAPI models...</info>');

        try {
            $service = new OpenApiService(new OpenApiLoader(), new FileWriter());
            $service->generate($config, dirname($configFile));
            $output->writeln('<info>Done.</info>');
        } catch (\Throwable $e) {
            $output->writeln('<error>Generation failed: ' . $e->getMessage() . '</error>');
            if ($output->isVerbose()) {
                $output->writeln($e->getTraceAsString());
            }

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
