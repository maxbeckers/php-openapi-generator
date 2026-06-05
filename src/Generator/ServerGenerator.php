<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Generator;

use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Generator\Context\ApiGroupContext;

/**
 * Generates server-side API interfaces and framework-specific helpers.
 */
readonly class ServerGenerator
{
    public function __construct(
        private GeneratorConfig $config,
        private TemplateEngine $templateEngine,
    ) {
    }

    /**
     * @param ApiGroupContext[] $groups
     *
     * @return array<string, string>  relative-path → content
     */
    public function generate(array $groups): array
    {
        $files = [];

        foreach ($groups as $group) {
            // Interface
            $interfaceContent = $this->templateEngine->render('server/interface.php.twig', [
                'config'        => $this->config,
                'group'         => $group,
                'useStatements' => $group->imports->getUseStatements(),
            ]);
            $interfaceFile = $this->outputPath($group->className . 'Interface.php');
            $files[$interfaceFile] = $interfaceContent;

            // Abstract controller (only when frameworkTarget != None)
            if ($this->config->frameworkTarget->value !== 'none') {
                $controllerContent = $this->templateEngine->render('server/controller.php.twig', [
                    'config'        => $this->config,
                    'group'         => $group,
                    'useStatements' => $group->imports->getUseStatements(),
                ]);
                $controllerFile = $this->outputPath($group->className . 'Controller.php');
                $files[$controllerFile] = $controllerContent;
            }

            if ($this->config->frameworkTarget->value === 'laravel') {
                $routesContent = $this->templateEngine->render('server/laravel-routes.php.twig', [
                    'group' => $group,
                ]);
                $routesFile = $this->outputPath($group->className . 'Routes.php');
                $files[$routesFile] = $routesContent;
            }
        }

        return $files;
    }

    private function outputPath(string $filename): string
    {
        $subDir = $this->config->apiOutputDir !== ''
            ? $this->config->apiOutputDir
            : 'Server';

        return $subDir . DIRECTORY_SEPARATOR . $filename;
    }
}
