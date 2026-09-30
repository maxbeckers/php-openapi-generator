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

        $firstGroup = $groups[0] ?? null;
        if ($firstGroup === null) {
            return $files;
        }

        if ($firstGroup->allSecuritySchemes !== []) {
            $files[$this->outputPath('ApiCredentials.php')] = $this->templateEngine->render('client/credentials.php.twig', [
                'config'    => $this->config,
                'namespace' => $firstGroup->namespace,
                'schemes'   => $firstGroup->allSecuritySchemes,
            ]);
        }

        if ($this->config->typedErrorResponses) {
            $exceptionNamespace = $firstGroup->namespace . '\\Exception';
            $files[$this->outputPath('Exception' . DIRECTORY_SEPARATOR . 'ApiException.php')] = $this->templateEngine->render(
                'client/exception/api-exception.php.twig',
                ['config' => $this->config, 'namespace' => $exceptionNamespace],
            );

            $exceptionClasses = [];
            foreach ($groups as $group) {
                foreach ($group->operations as $operation) {
                    $exceptionClasses += $operation->errorExceptionClasses();
                }
            }
            ksort($exceptionClasses);

            foreach ($exceptionClasses as $statusCode => $className) {
                $files[$this->outputPath('Exception' . DIRECTORY_SEPARATOR . $className . '.php')] = $this->templateEngine->render(
                    'client/exception/status-exception.php.twig',
                    [
                        'config'     => $this->config,
                        'namespace'  => $exceptionNamespace,
                        'className'  => $className,
                        'statusCode' => $statusCode,
                    ],
                );
            }
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
