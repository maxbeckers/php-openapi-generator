<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Loader;

use MaxBeckers\YamlParser\YamlParser;

class LocalReferenceResolver
{
    /** @var array<string, array<string, mixed>> */
    private array $documentCache = [];

    /** @var array<string, array<string, mixed>> */
    private array $imports = [];

    /** @var array<string, string> */
    private array $componentSources = [];

    /** @var array<string, mixed> */
    private array $rootDocument = [];

    private string $rootPath = '';
    private string $rootDirectory = '';

    public function __construct(private readonly bool $allowReferencesOutsideRoot = false)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $path): array
    {
        $this->documentCache = [];
        $this->imports = [];
        $this->componentSources = [];

        $this->rootPath = $this->canonicalPath($path, null, true);
        $this->rootDirectory = dirname($this->rootPath);
        $this->rootDocument = $this->loadDocument($this->rootPath);

        $resolved = $this->rootDocument;
        $components = $this->rootDocument['components'] ?? [];

        if (is_array($components)) {
            $this->registerRootComponentSources($components);
            $resolvedComponents = [];
            foreach ($components as $category => $items) {
                if (!is_array($items)) {
                    $resolvedComponents[$category] = $items;

                    continue;
                }

                foreach ($items as $name => $item) {
                    $resolvedComponents[$category][$name] = $this->resolveNode(
                        $item,
                        $this->rootPath,
                        ['components', (string) $category, (string) $name],
                        [],
                    );
                }
            }
            $resolved['components'] = $resolvedComponents;
        }

        foreach ($this->rootDocument as $key => $value) {
            if ($key === 'components') {
                continue;
            }

            $resolved[$key] = $this->resolveNode($value, $this->rootPath, [(string) $key], []);
        }

        return $this->mergeImports($resolved);
    }

    /**
     * Parse one YAML or JSON document. Kept protected so alternate parsers and
     * cache assertions can be supplied without changing the loader API.
     *
     * @return array<string, mixed>
     */
    protected function parseDocument(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new ReferenceResolutionException(sprintf('Unable to read referenced document "%s".', $path));
        }

        try {
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'json') {
                $parsed = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } else {
                $parsed = (new YamlParser())->parsePlainArray($contents);
            }
        } catch (\Throwable $exception) {
            throw new ReferenceResolutionException(
                sprintf('Unable to parse OpenAPI document "%s": %s', $path, $exception->getMessage()),
                0,
                $exception,
            );
        }

        $parsed = $this->normalise($parsed);
        if (!is_array($parsed)) {
            throw new ReferenceResolutionException(
                sprintf('OpenAPI document "%s" must contain an object or array.', $path),
            );
        }

        return $parsed;
    }

    /**
     * @param mixed $node
     * @param string[] $location
     * @param string[] $activeReferences
     *
     * @return mixed
     */
    private function resolveNode(
        mixed $node,
        string $documentPath,
        array $location,
        array $activeReferences,
    ): mixed {
        if (!is_array($node)) {
            return $node;
        }

        if (isset($node['$ref'])) {
            if (!is_string($node['$ref']) || $node['$ref'] === '') {
                throw new ReferenceResolutionException(
                    sprintf('Invalid $ref at %s: the reference must be a non-empty string.', $this->formatLocation($documentPath, $location)),
                );
            }

            if (count($node) > 1) {
                throw new ReferenceResolutionException(
                    sprintf(
                        'Unsupported $ref siblings at %s. Reference Objects with sibling fields are not supported.',
                        $this->formatLocation($documentPath, $location),
                    ),
                );
            }

            return $this->resolveReference($node['$ref'], $documentPath, $location, $activeReferences);
        }

        $resolved = [];
        foreach ($node as $key => $value) {
            $resolved[$key] = $this->resolveNode(
                $value,
                $documentPath,
                [...$location, (string) $key],
                $activeReferences,
            );
        }

        return $resolved;
    }

    /**
     * @param string[] $location
     * @param string[] $activeReferences
     *
     * @return array<string, mixed>
     */
    private function resolveReference(
        string $reference,
        string $documentPath,
        array $location,
        array $activeReferences,
    ): array {
        [$filePart, $fragment] = $this->splitReference($reference, $documentPath, $location);
        $isExternal = $filePart !== '';

        if (!$isExternal && $documentPath === $this->rootPath) {
            $this->evaluatePointer($this->rootDocument, $fragment, $this->rootPath, $reference);

            return ['$ref' => $reference];
        }

        $targetPath = $isExternal
            ? $this->canonicalPath(rawurldecode($filePart), dirname($documentPath))
            : $documentPath;
        $targetDocument = $this->loadDocument($targetPath);
        $target = $this->evaluatePointer($targetDocument, $fragment, $targetPath, $reference);

        if (!is_array($target)) {
            throw new ReferenceResolutionException(
                sprintf(
                    'Reference "%s" at %s resolves to %s; an object or array is required.',
                    $reference,
                    $this->formatLocation($documentPath, $location),
                    get_debug_type($target),
                ),
            );
        }

        $targetId = $targetPath . '#' . $fragment;
        $component = $this->componentFromFragment($fragment);

        if ($component !== null) {
            [$category, $name] = $component;
            $componentKey = $category . '/' . $name;
            $localReference = '#/components/' . $this->encodePointerToken($category) . '/' . $this->encodePointerToken($name);

            if ($this->isRootComponentLocation($location, $category, $name)) {
                $this->assertNoExternalCycle($targetId, $activeReferences, $isExternal);
                $this->componentSources[$componentKey] = $targetId;

                return $this->resolveNode($target, $targetPath, $location, [...$activeReferences, $targetId]);
            }

            if (isset($this->componentSources[$componentKey])) {
                if ($this->componentSources[$componentKey] !== $targetId) {
                    throw new ReferenceResolutionException(
                        sprintf(
                            'Component name conflict for "%s": "%s" and "%s" resolve to the same local component.',
                            $componentKey,
                            $this->componentSources[$componentKey],
                            $targetId,
                        ),
                    );
                }

                $this->assertNoExternalCycle($targetId, $activeReferences, $isExternal);

                return ['$ref' => $localReference];
            }

            if (isset($this->rootDocument['components'][$category][$name])) {
                throw new ReferenceResolutionException(
                    sprintf(
                        'External reference "%s" conflicts with existing root component "%s".',
                        $reference,
                        $componentKey,
                    ),
                );
            }

            $this->assertNoExternalCycle($targetId, $activeReferences, $isExternal);
            $this->componentSources[$componentKey] = $targetId;
            $this->imports[$category][$name] = $this->resolveNode(
                $target,
                $targetPath,
                ['components', $category, $name],
                [...$activeReferences, $targetId],
            );

            return ['$ref' => $localReference];
        }

        $this->assertNoCycle($targetId, $activeReferences);

        return $this->resolveNode($target, $targetPath, $location, [...$activeReferences, $targetId]);
    }

    /**
     * @param string[] $location
     *
     * @return array{string, string}
     */
    private function splitReference(string $reference, string $documentPath, array $location): array
    {
        $hashPosition = strpos($reference, '#');
        $filePart = $hashPosition === false ? $reference : substr($reference, 0, $hashPosition);
        $fragment = $hashPosition === false ? '' : substr($reference, $hashPosition + 1);

        if ($filePart !== '' && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $filePart) === 1
            && preg_match('/^[A-Za-z]:[\\\\\/]/', $filePart) !== 1
        ) {
            throw new ReferenceResolutionException(
                sprintf(
                    'Unsupported URI reference "%s" at %s. Only local filesystem references are supported.',
                    $reference,
                    $this->formatLocation($documentPath, $location),
                ),
            );
        }

        if (str_contains($filePart, '?')) {
            throw new ReferenceResolutionException(
                sprintf('Unsupported query string in reference "%s" at %s.', $reference, $this->formatLocation($documentPath, $location)),
            );
        }

        if ($fragment !== '' && $fragment[0] !== '/') {
            throw new ReferenceResolutionException(
                sprintf('Invalid JSON Pointer fragment in reference "%s": fragments must be empty or begin with "/".', $reference),
            );
        }

        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $fragment) === 1) {
            throw new ReferenceResolutionException(
                sprintf('Invalid percent-encoding in JSON Pointer fragment for reference "%s".', $reference),
            );
        }

        return [$filePart, rawurldecode($fragment)];
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return mixed
     */
    private function evaluatePointer(array $document, string $fragment, string $path, string $reference): mixed
    {
        if ($fragment === '') {
            return $document;
        }

        $current = $document;
        foreach (explode('/', substr($fragment, 1)) as $encodedToken) {
            if (preg_match('/~(?![01])/', $encodedToken) === 1) {
                throw new ReferenceResolutionException(
                    sprintf('Invalid JSON Pointer escape in reference "%s". Only "~0" and "~1" are valid.', $reference),
                );
            }

            $token = str_replace(['~1', '~0'], ['/', '~'], $encodedToken);
            if (!is_array($current) || !array_key_exists($token, $current)) {
                throw new ReferenceResolutionException(
                    sprintf('JSON Pointer "%s" was not found in document "%s" for reference "%s".', $fragment, $path, $reference),
                );
            }

            $current = $current[$token];
        }

        return $current;
    }

    /**
     * @return array{string, string}|null
     */
    private function componentFromFragment(string $fragment): ?array
    {
        if ($fragment === '') {
            return null;
        }

        $tokens = explode('/', substr($fragment, 1));
        if (count($tokens) !== 3 || $tokens[0] !== 'components') {
            return null;
        }

        foreach ($tokens as &$token) {
            if (preg_match('/~(?![01])/', $token) === 1) {
                return null;
            }
            $token = str_replace(['~1', '~0'], ['/', '~'], $token);
        }

        return [$tokens[1], $tokens[2]];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadDocument(string $path): array
    {
        if (!isset($this->documentCache[$path])) {
            $this->documentCache[$path] = $this->parseDocument($path);
        }

        return $this->documentCache[$path];
    }

    /**
     * Reserve root component names before resolving any of their nested refs.
     * This lets several root components point into the same external registry
     * without the first component treating the others as conflicting imports.
     *
     * @param array<string, mixed> $components
     */
    private function registerRootComponentSources(array $components): void
    {
        foreach ($components as $category => $items) {
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $name => $item) {
                if (!is_array($item) || count($item) !== 1 || !isset($item['$ref']) || !is_string($item['$ref'])) {
                    continue;
                }

                $location = ['components', (string) $category, (string) $name];
                [$filePart, $fragment] = $this->splitReference(
                    $item['$ref'],
                    $this->rootPath,
                    $location,
                );
                $targetComponent = $this->componentFromFragment($fragment);

                if ($filePart === '' || $targetComponent !== [(string) $category, (string) $name]) {
                    continue;
                }

                $targetPath = $this->canonicalPath(rawurldecode($filePart), $this->rootDirectory);
                $this->componentSources[(string) $category . '/' . (string) $name] = $targetPath . '#' . $fragment;
            }
        }
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function mergeImports(array $document): array
    {
        foreach ($this->imports as $category => $items) {
            foreach ($items as $name => $item) {
                if (isset($document['components'][$category][$name])) {
                    continue;
                }

                $document['components'][$category][$name] = $item;
            }
        }

        return $document;
    }

    private function canonicalPath(
        string $path,
        ?string $baseDirectory,
        bool $root = false,
    ): string {
        $candidate = $this->isAbsolutePath($path) || $baseDirectory === null
            ? $path
            : $baseDirectory . DIRECTORY_SEPARATOR . $path;
        $canonical = realpath($candidate);

        if ($canonical === false || !is_file($canonical)) {
            if ($root) {
                throw new \InvalidArgumentException(sprintf('OpenAPI spec file not found: %s', $path));
            }

            throw new ReferenceResolutionException(sprintf('Referenced OpenAPI file not found: %s', $candidate));
        }

        if (!$root && !$this->allowReferencesOutsideRoot && !$this->isWithinRoot($canonical)) {
            throw new ReferenceResolutionException(
                sprintf(
                    'Referenced file "%s" is outside the root document directory "%s".',
                    $canonical,
                    $this->rootDirectory,
                ),
            );
        }

        return $canonical;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function isWithinRoot(string $path): bool
    {
        $root = rtrim($this->rootDirectory, '/\\') . DIRECTORY_SEPARATOR;
        $candidate = rtrim($path, '/\\');

        if (DIRECTORY_SEPARATOR === '\\') {
            $root = strtolower($root);
            $candidate = strtolower($candidate);
        }

        return $candidate === rtrim($root, DIRECTORY_SEPARATOR) || str_starts_with($candidate, $root);
    }

    /**
     * @param string[] $activeReferences
     */
    private function assertNoExternalCycle(string $targetId, array $activeReferences, bool $isExternal): void
    {
        if (!$isExternal) {
            return;
        }

        $this->assertNoCycle($targetId, $activeReferences);
    }

    /**
     * @param string[] $activeReferences
     */
    private function assertNoCycle(string $targetId, array $activeReferences): void
    {
        $cycleStart = array_search($targetId, $activeReferences, true);
        if ($cycleStart === false) {
            return;
        }

        $chain = [...array_slice($activeReferences, $cycleStart), $targetId];

        throw new ReferenceResolutionException(
            sprintf('Circular external $ref detected: %s', implode(' -> ', $chain)),
        );
    }

    /**
     * @param string[] $location
     */
    private function isRootComponentLocation(array $location, string $category, string $name): bool
    {
        return $location === ['components', $category, $name];
    }

    /**
     * @param string[] $location
     */
    private function formatLocation(string $documentPath, array $location): string
    {
        $pointer = implode('/', array_map($this->encodePointerToken(...), $location));

        return $documentPath . '#/' . $pointer;
    }

    private function encodePointerToken(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }

    private function normalise(mixed $value): mixed
    {
        if ($value instanceof \Traversable) {
            $value = iterator_to_array($value);
        }

        if (is_array($value)) {
            $normalised = [];
            foreach ($value as $key => $item) {
                $normalised[$key] = $this->normalise($item);
            }

            return $normalised;
        }

        return $value;
    }
}
