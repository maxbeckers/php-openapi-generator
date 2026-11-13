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
use MaxBeckers\OpenApiGenerator\Validation\Diagnostic;
use MaxBeckers\OpenApiGenerator\Validation\DiagnosticBag;
use MaxBeckers\OpenApiGenerator\Validation\PreflightValidator;
use MaxBeckers\OpenApiGenerator\Validation\ValidationException;

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
    public function generate(GeneratorConfig $config, string $configDir): DiagnosticBag
    {
        $diagnostics = new DiagnosticBag();
        $files = $this->prepare($config, $configDir, $diagnostics);
        if ($diagnostics->hasErrors()) {
            throw new ValidationException($diagnostics);
        }
        $outputDir = $this->resolvePath($config->outputDir, $configDir);
        $this->fileWriter->writeAll($outputDir, $files);

        return $diagnostics;
    }

    /**
     * Exercise the same preparation pipeline without creating output files.
     * Unexpected implementation/plugin exceptions deliberately propagate.
     */
    public function validate(GeneratorConfig $config, string $configDir): DiagnosticBag
    {
        $diagnostics = new DiagnosticBag();
        $this->prepare($config, $configDir, $diagnostics);

        return $diagnostics;
    }

    /** @return array<string, string> */
    private function prepare(GeneratorConfig $config, string $configDir, DiagnosticBag $diagnostics): array
    {
        $validator = new PreflightValidator();
        $validator->validateConfig($config, $diagnostics);
        $specFile = $this->resolvePath($config->specFile, $configDir);
        $spec = $this->loader->inspectFile($specFile, $diagnostics);
        if ($spec === null) {
            return [];
        }
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

        $validator->validateSpec($spec, $this->loader->inspectedDocument(), $config, $diagnostics, $this->loader->sourceLocation(...));
        if ($diagnostics->hasErrors()) {
            return [];
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
        $filePaths = [];
        $symbols = [];
        foreach ($contexts as $context) {
            if ($modelGenerator->canGenerate($context)) {
                $this->addSymbol($symbols, $context->schema->namespace . '\\' . $context->schema->className, $diagnostics);
                $generated = $modelGenerator->generate($context);
                foreach ($generated as $path => $content) {
                    $this->addFile($files, $filePaths, $path, $content, $diagnostics);
                }
            }
        }

        if ($config->generationTarget !== GenerationTarget::Server
            || $config->apiOutputDir !== ''
            || $config->apiNamespace !== ''
        ) {
            $opBuilder = new OperationContextBuilder($config, $naming, $resolver);
            $groups = $opBuilder->build($spec);
            foreach ($groups as $group) {
                $suffixes = $config->generationTarget === GenerationTarget::Client ? ['Client', 'ClientInterface'] : ['Interface'];
                if ($config->generationTarget === GenerationTarget::Server && $config->frameworkTarget->value !== 'none') {
                    $suffixes[] = 'Controller';
                    if ($config->frameworkTarget->value === 'laravel') {
                        $suffixes[] = 'Routes';
                    }
                }
                foreach ($suffixes as $suffix) {
                    $this->addSymbol($symbols, $group->namespace . '\\' . $group->className . $suffix, $diagnostics);
                }
            }
            if ($config->generationTarget === GenerationTarget::Client && isset($groups[0])) {
                if ($groups[0]->allSecuritySchemes !== []) {
                    $this->addSymbol($symbols, $groups[0]->namespace . '\\ApiCredentials', $diagnostics);
                }
                if ($config->typedErrorResponses) {
                    $this->addSymbol($symbols, $groups[0]->namespace . '\\Exception\\ApiException', $diagnostics);
                    $exceptionClasses = [];
                    foreach ($groups as $group) {
                        foreach ($group->operations as $operation) {
                            $exceptionClasses += $operation->errorExceptionClasses();
                        }
                    }
                    foreach ($exceptionClasses as $class) {
                        $this->addSymbol($symbols, $groups[0]->namespace . '\\Exception\\' . $class, $diagnostics);
                    }
                }
            }

            if ($config->generationTarget === GenerationTarget::Server) {
                $serverGen = new ServerGenerator($config, $templateEngine);
                foreach ($serverGen->generate($groups) as $path => $content) {
                    $this->addFile($files, $filePaths, $path, $content, $diagnostics);
                }
            } else {
                $clientGen = new ClientGenerator($config, $templateEngine);
                foreach ($clientGen->generate($groups) as $path => $content) {
                    $this->addFile($files, $filePaths, $path, $content, $diagnostics);
                }
            }
        }

        return $files;
    }

    /**
     * @param array<string, string> $files
     * @param array<string, string> $filePaths
     */
    private function addFile(array &$files, array &$filePaths, string $path, string $content, DiagnosticBag $diagnostics): void
    {
        $key = strtolower(str_replace('\\', '/', $path));
        if (isset($filePaths[$key])) {
            $diagnostics->add(new Diagnostic(
                'name.file-collision',
                'error',
                '',
                'Generated output paths collide: ' . $filePaths[$key] . ' and ' . $path . '.',
                'Use distinct model/API output directories and names.',
            ));
        }
        $filePaths[$key] = $path;
        $files[$path] = $content;
    }

    /** @param array<string, string> $symbols */
    private function addSymbol(array &$symbols, string $name, DiagnosticBag $diagnostics): void
    {
        $key = strtolower(ltrim($name, '\\'));
        if (isset($symbols[$key])) {
            $diagnostics->add(new Diagnostic(
                'name.symbol-collision',
                'error',
                '',
                'Generated PHP symbols collide: ' . $symbols[$key] . ' and ' . $name . '.',
                'Use distinct namespaces or generated class names.',
            ));
        }
        $symbols[$key] = $name;
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
