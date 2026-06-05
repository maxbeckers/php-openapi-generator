<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Service;

use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;
use MaxBeckers\OpenApiGenerator\FileWriter\FileWriter;
use MaxBeckers\OpenApiGenerator\Generator\ClientGenerator;
use MaxBeckers\OpenApiGenerator\Generator\ModelGenerator;
use MaxBeckers\OpenApiGenerator\Generator\NamingStrategy;
use MaxBeckers\OpenApiGenerator\Generator\OperationContextBuilder;
use MaxBeckers\OpenApiGenerator\Generator\OperationFilter;
use MaxBeckers\OpenApiGenerator\Generator\SchemaResolver;
use MaxBeckers\OpenApiGenerator\Generator\ServerGenerator;
use MaxBeckers\OpenApiGenerator\Generator\TemplateEngine;
use MaxBeckers\OpenApiGenerator\Loader\OpenApiLoader;
use MaxBeckers\OpenApiGenerator\Plugin\Builtin\SensitivePlugin;
use MaxBeckers\OpenApiGenerator\Plugin\Builtin\TrimPlugin;

/**
 * Orchestrates the full generation pipeline:
 *
 * 1. Load the OpenAPI spec.
 * 2. Apply operation filters.
 * 3. Resolve & classify schemas.
 * 4. Build generation contexts.
 * 5. Generate code via ModelGenerator.
 * 6. Write files via FileWriter.
 */
readonly class OpenApiService
{
    public function __construct(
        private OpenApiLoader $loader,
        private FileWriter $fileWriter,
    ) {
    }

    /**
     * Run the full generation pipeline.
     *
     * @param string $configDir absolute directory containing the config file
     *                          (used to resolve relative paths in the config)
     */
    public function generate(GeneratorConfig $config, string $configDir): void
    {
        $specFile = $this->resolvePath($config->specFile, $configDir);
        $outputDir = $this->resolvePath($config->outputDir, $configDir);

        $spec = $this->loader->loadFile($specFile);
        $generationMeta = [
            'generatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'openapiVersion' => $spec->openapi !== '' ? $spec->openapi : null,
            'apiVersion' => $spec->info->version !== '' ? $spec->info->version : null,
            'generatorVersion' => $this->resolveGeneratorVersion(),
        ];

        if (
            !empty($config->includeTags) || !empty($config->excludeTags)
            || !empty($config->includeOperationIds) || !empty($config->excludeOperationIds)
            || !empty($config->includePaths) || !empty($config->excludePaths)
        ) {
            $filter = new OperationFilter();
            $spec = $filter->filter(
                $spec,
                $config->includeTags,
                $config->excludeTags,
                $config->includeOperationIds,
                $config->excludeOperationIds,
                $config->includePaths,
                $config->excludePaths,
            );
        }

        $naming = new NamingStrategy($config);
        $resolver = new SchemaResolver($naming);
        $templateBasePath = $this->findTemplatesDir();
        $templateEngine = new TemplateEngine($templateBasePath, $config->phpVersion, [
            'generationMeta' => $generationMeta,
        ]);

        $modelGenerator = new ModelGenerator($config, $naming, $resolver, $templateEngine);

        if (!$config->disableBuiltinPlugins) {
            $modelGenerator->addPropertyPlugin(new TrimPlugin());
            $modelGenerator->addPropertyPlugin(new SensitivePlugin());
        }

        foreach ($config->getPropertyPlugins() as $plugin) {
            $modelGenerator->addPropertyPlugin($plugin);
        }
        foreach ($config->getSchemaPlugins() as $plugin) {
            $modelGenerator->addSchemaPlugin($plugin);
        }

        $kinds = $resolver->resolve($spec->components);

        $contexts = $modelGenerator->buildContexts($spec->components, $kinds);

        $files = [];
        foreach ($contexts as $context) {
            if ($modelGenerator->canGenerate($context)) {
                $generated = $modelGenerator->generate($context);
                foreach ($generated as $path => $content) {
                    $files[$path] = $content;
                }
            }
        }

        if ($config->generationTarget !== GenerationTarget::Server
            || $config->apiOutputDir !== ''
            || $config->apiNamespace !== ''
        ) {
            $opBuilder = new OperationContextBuilder($config, $naming, $resolver);
            $groups = $opBuilder->build($spec);

            if ($config->generationTarget === GenerationTarget::Server) {
                $serverGen = new ServerGenerator($config, $templateEngine);
                foreach ($serverGen->generate($groups) as $path => $content) {
                    $files[$path] = $content;
                }
            } else {
                $clientGen = new ClientGenerator($config, $templateEngine);
                foreach ($clientGen->generate($groups) as $path => $content) {
                    $files[$path] = $content;
                }
            }
        }

        $this->fileWriter->writeAll($outputDir, $files);
    }

    /**
     * Remove all generated files in the configured output directory.
     */
    public function clean(GeneratorConfig $config, string $configDir): void
    {
        $outputDir = $this->resolvePath($config->outputDir, $configDir);
        $this->fileWriter->clean($outputDir);
    }

    private function resolvePath(string $path, string $baseDir): string
    {
        if ($path === '' || $path[0] === '/' || (strlen($path) > 1 && $path[1] === ':')) {
            return $path;
        }

        return rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . $path;
    }

    private function findTemplatesDir(): string
    {
        $candidates = [
            __DIR__ . '/../../templates',
            __DIR__ . '/../../../templates',
        ];

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && is_dir($real)) {
                return $real;
            }
        }

        throw new \RuntimeException('Could not locate the templates/ directory.');
    }

    private function resolveGeneratorVersion(): ?string
    {
        if (!class_exists(\Composer\InstalledVersions::class)) {
            return null;
        }

        if (!\Composer\InstalledVersions::isInstalled('maxbeckers/php-openapi-generator')) {
            return null;
        }

        return \Composer\InstalledVersions::getPrettyVersion('maxbeckers/php-openapi-generator');
    }
}
