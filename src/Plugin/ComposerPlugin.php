<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Plugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use MaxBeckers\OpenApiGenerator\FileWriter\FileWriter;
use MaxBeckers\OpenApiGenerator\Loader\ConfigFileLoader;
use MaxBeckers\OpenApiGenerator\Loader\OpenApiLoader;
use MaxBeckers\OpenApiGenerator\Service\OpenApiService;

class ComposerPlugin implements PluginInterface, EventSubscriberInterface
{
    private Composer $composer;
    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_AUTOLOAD_DUMP => 'onPostAutoloadDump',
        ];
    }

    public function onPostAutoloadDump(Event $event): void
    {
        $vendorDir = $this->composer->getConfig()->get('vendor-dir');
        $projectDir = dirname($vendorDir);

        $configLoader = new ConfigFileLoader();
        $configFile = $configLoader->findConfigFile($projectDir);

        if ($configFile === null) {
            return;
        }

        try {
            $config = $configLoader->load($configFile);
        } catch (\Throwable $e) {
            $this->io->writeError('<warning>[openapi-generator] Failed to load config: ' . $e->getMessage() . '</warning>');

            return;
        }

        if (!$config->autoGenerate) {
            return;
        }

        $this->io->write('<info>[openapi-generator] Generating OpenAPI models...</info>');

        try {
            $service = new OpenApiService(new OpenApiLoader(), new FileWriter());
            $service->generate($config, dirname($configFile));
            $this->io->write('<info>[openapi-generator] Done.</info>');
        } catch (\Throwable $e) {
            $this->io->writeError('<error>[openapi-generator] Generation failed: ' . $e->getMessage() . '</error>');
        }
    }
}
