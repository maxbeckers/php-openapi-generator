<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Command;

use MaxBeckers\OpenApiGenerator\FileWriter\FileWriter;
use MaxBeckers\OpenApiGenerator\Loader\ConfigFileLoader;
use MaxBeckers\OpenApiGenerator\Loader\OpenApiLoader;
use MaxBeckers\OpenApiGenerator\Service\OpenApiService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'clean', description: 'Remove all generated PHP model files')]
class CleanCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'config',
            'c',
            InputOption::VALUE_OPTIONAL,
            'Path to the php-openapi-generator.php config file',
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

        $output->writeln('<info>Cleaning generated files...</info>');

        try {
            $service = new OpenApiService(new OpenApiLoader(), new FileWriter());
            $service->clean($config, dirname($configFile));
            $output->writeln('<info>Done.</info>');
        } catch (\Throwable $e) {
            $output->writeln('<error>Clean failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
