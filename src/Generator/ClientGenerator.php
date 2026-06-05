<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Generator;

use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Generator\Context\ApiGroupContext;

/**
 * Generates client-side HTTP service classes and their interfaces.
 */
readonly class ClientGenerator
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
            // Client interface
            $interfaceContent = $this->templateEngine->render('client/interface.php.twig', [
                'config'        => $this->config,
                'group'         => $group,
                'useStatements' => $group->imports->getUseStatements(),
            ]);
            $interfaceFile = $this->outputPath($group->className . 'ClientInterface.php');
            $files[$interfaceFile] = $interfaceContent;

            // Concrete client
            $clientContent = $this->templateEngine->render('client/class.php.twig', [
                'config'        => $this->config,
                'group'         => $group,
                'useStatements' => $group->imports->getUseStatements(),
            ]);
            $clientFile = $this->outputPath($group->className . 'Client.php');
            $files[$clientFile] = $clientContent;
        }

        return $files;
    }

    private function outputPath(string $filename): string
    {
        $subDir = $this->config->apiOutputDir !== ''
            ? $this->config->apiOutputDir
            : 'Client';

        return $subDir . DIRECTORY_SEPARATOR . $filename;
    }
}
