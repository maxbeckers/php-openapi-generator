<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Generator;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * Thin wrapper around Twig that loads templates from the versioned template
 * directory (e.g. templates/php82/), with a fallback to the nearest available
 * version when an exact match is not present.
 */
class TemplateEngine
{
    private Environment $twig;

    /**
     * @param string               $templateBasePath Absolute path to the templates/ directory
     * @param string               $phpVersion       PHP version string, e.g. "8.2"
     * @param array<string, mixed> $globals          Shared Twig globals available in every template
     */
    public function __construct(string $templateBasePath, string $phpVersion, array $globals = [])
    {
        $versionedDir = $this->resolveTemplateDir($templateBasePath, $phpVersion);

        if ($versionedDir === null) {
            throw new \InvalidArgumentException(sprintf(
                'Template directory not found for PHP %s under %s',
                $phpVersion,
                $templateBasePath
            ));
        }

        $loader = new FilesystemLoader($versionedDir);

        $this->twig = new Environment($loader, [
            'autoescape'       => false,
            'strict_variables' => true,
        ]);

        $defaultGlobals = [
            'generationMeta' => [
                'generatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
                'openapiVersion' => null,
                'apiVersion' => null,
                'generatorVersion' => null,
            ],
        ];

        foreach (array_merge($defaultGlobals, $globals) as $name => $value) {
            $this->twig->addGlobal($name, $value);
        }

        $this->registerFilters();
    }

    /**
     * Render a template file with the given context variables.
     *
     * @param array<string, mixed> $context
     */
    public function render(string $templateName, array $context): string
    {
        return $this->twig->render($templateName, $context);
    }

    // -------------------------------------------------------------------------
    // Custom Twig filters / functions
    // -------------------------------------------------------------------------

    private function registerFilters(): void
    {
        // camel_case: converts "foo_bar" or "FooBar" to "fooBar"
        $this->twig->addFilter(new TwigFilter('camel_case', function (string $input): string {
            $words = preg_split('/[^a-zA-Z0-9]+/', $input) ?: [$input];
            $result = array_map('ucfirst', $words);

            return lcfirst(implode('', $result));
        }));

        // pascal_case: converts "foo_bar" to "FooBar"
        $this->twig->addFilter(new TwigFilter('pascal_case', function (string $input): string {
            $words = preg_split('/[^a-zA-Z0-9]+/', $input) ?: [$input];

            return implode('', array_map('ucfirst', $words));
        }));

        // php_export: like var_export but returns the string
        $this->twig->addFilter(new TwigFilter('php_export', function (mixed $value): string {
            return var_export($value, true);
        }));
    }

    private function resolveTemplateDir(string $templateBasePath, string $phpVersion): ?string
    {
        $requestedVersion = $this->normalizePhpVersion($phpVersion);
        $exactDir = $templateBasePath . DIRECTORY_SEPARATOR . 'php' . $requestedVersion;

        if (is_dir($exactDir)) {
            return $exactDir;
        }

        $requestedVersionValue = $this->versionToInt($requestedVersion);
        $bestMatch = null;
        $bestMatchValue = null;
        $highestMatch = null;
        $highestMatchValue = null;

        foreach (glob($templateBasePath . DIRECTORY_SEPARATOR . 'php*', GLOB_ONLYDIR) ?: [] as $candidateDir) {
            if (!preg_match('/php(\d+)(\d+)$/', basename($candidateDir), $matches)) {
                continue;
            }

            $candidateVersion = $matches[1] . $matches[2];
            $candidateValue = $this->versionToInt($candidateVersion);

            if ($candidateValue <= $requestedVersionValue && ($bestMatchValue === null || $candidateValue > $bestMatchValue)) {
                $bestMatch = $candidateDir;
                $bestMatchValue = $candidateValue;
            }

            if ($highestMatchValue === null || $candidateValue > $highestMatchValue) {
                $highestMatch = $candidateDir;
                $highestMatchValue = $candidateValue;
            }
        }

        return $bestMatch ?? $highestMatch;
    }

    private function normalizePhpVersion(string $phpVersion): string
    {
        if (preg_match('/^(\d+)\.(\d+)/', trim($phpVersion), $matches) === 1) {
            return $matches[1] . $matches[2];
        }

        return preg_replace('/\D+/', '', $phpVersion) ?: $phpVersion;
    }

    private function versionToInt(string $phpVersion): int
    {
        if (strlen($phpVersion) >= 2) {
            $major = (int) substr($phpVersion, 0, 1);
            $minor = (int) substr($phpVersion, 1);

            return ($major * 100) + $minor;
        }

        return (int) $phpVersion;
    }
}
