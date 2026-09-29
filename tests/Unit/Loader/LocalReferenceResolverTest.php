<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Unit\Loader;

use MaxBeckers\OpenApiGenerator\Loader\LocalReferenceResolver;
use MaxBeckers\OpenApiGenerator\Loader\OpenApiLoader;
use MaxBeckers\OpenApiGenerator\Loader\ReferenceResolutionException;
use PHPUnit\Framework\TestCase;

class LocalReferenceResolverTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/split-spec/openapi.yaml';

    /** @var string[] */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }
    }

    public function testLoadsSplitYamlAndJsonDocumentsWithNestedReferences(): void
    {
        $spec = (new OpenApiLoader())->loadFile(self::FIXTURE);

        self::assertSame('Split API', $spec->info->title);
        self::assertArrayHasKey('User', $spec->components->schemas);
        self::assertArrayHasKey('Address', $spec->components->schemas);
        self::assertSame(
            '#/components/schemas/Address',
            $spec->components->schemas['User']->properties['address']->ref,
        );
        self::assertSame(['resolved'], $spec->components->schemas['EscapedPointer']->enum);

        $get = $spec->paths['/users/{userId}']->get;
        self::assertNotNull($get);
        self::assertSame(['userId', 'verbose'], array_map(static fn ($parameter) => $parameter->name, $get->parameters));
        $successResponse = null;
        foreach ($get->responses as $statusCode => $response) {
            if ((string) $statusCode === '200') {
                $successResponse = $response;

                break;
            }
        }
        self::assertNotNull($successResponse);
        self::assertSame(
            '#/components/schemas/User',
            $successResponse->content['application/json']->schema->ref,
        );

        $post = $spec->paths['/users/{userId}']->post;
        self::assertNotNull($post);
        self::assertNotNull($post->requestBody);
        self::assertTrue($post->requestBody->required);
        self::assertSame(
            '#/components/schemas/User',
            $post->requestBody->content['application/json']->schema->ref,
        );
        self::assertSame('bearer', $spec->components->securitySchemes['BearerAuth']->scheme);
    }

    public function testParsesEveryReferencedDocumentOnlyOnce(): void
    {
        $resolver = new class () extends LocalReferenceResolver {
            /** @var array<string, int> */
            public array $parseCounts = [];

            protected function parseDocument(string $path): array
            {
                $this->parseCounts[$path] = ($this->parseCounts[$path] ?? 0) + 1;

                return parent::parseDocument($path);
            }
        };

        (new OpenApiLoader($resolver))->loadFile(self::FIXTURE);

        self::assertNotEmpty($resolver->parseCounts);
        self::assertSame([1], array_values(array_unique($resolver->parseCounts)));
        self::assertSame(1, $resolver->parseCounts[realpath(__DIR__ . '/../../Fixtures/split-spec/components/models.json')]);
    }

    public function testMissingReferencedFileHasActionableError(): void
    {
        $root = $this->createSpec("components:\n  schemas:\n    Missing:\n      \$ref: './missing.yaml'\n");

        $this->expectException(ReferenceResolutionException::class);
        $this->expectExceptionMessage('Referenced OpenAPI file not found');
        (new OpenApiLoader())->loadFile($root);
    }

    public function testMalformedJsonHasActionableError(): void
    {
        $directory = $this->createDirectory();
        $this->write($directory . '/openapi.yaml', $this->baseSpec("components:\n  schemas:\n    Broken:\n      \$ref: './broken.json'\n"));
        $this->write($directory . '/broken.json', '{"type":');

        $this->expectException(ReferenceResolutionException::class);
        $this->expectExceptionMessage('Unable to parse OpenAPI document');
        (new OpenApiLoader())->loadFile($directory . '/openapi.yaml');
    }

    public function testMalformedYamlHasActionableError(): void
    {
        $directory = $this->createDirectory();
        $this->write($directory . '/openapi.yaml', $this->baseSpec("components:\n  schemas:\n    Broken:\n      \$ref: './broken.yaml'\n"));
        $this->write($directory . '/broken.yaml', "type: object\n  invalid: indentation\n");

        $this->expectException(ReferenceResolutionException::class);
        $this->expectExceptionMessage('Unable to parse OpenAPI document');
        (new OpenApiLoader())->loadFile($directory . '/openapi.yaml');
    }

    public function testMissingJsonPointerFailsEarly(): void
    {
        $directory = $this->createDirectory();
        $this->write($directory . '/openapi.yaml', $this->baseSpec("components:\n  schemas:\n    Broken:\n      \$ref: './schema.json#/\$defs/Missing'\n"));
        $this->write($directory . '/schema.json', '{"$defs":{"Present":{"type":"string"}}}');

        $this->expectException(ReferenceResolutionException::class);
        $this->expectExceptionMessage('was not found');
        (new OpenApiLoader())->loadFile($directory . '/openapi.yaml');
    }

    public function testInvalidJsonPointerEscapeFailsEarly(): void
    {
        $directory = $this->createDirectory();
        $this->write($directory . '/openapi.yaml', $this->baseSpec("components:\n  schemas:\n    Broken:\n      \$ref: './schema.json#/\$defs/Bad~2Name'\n"));
        $this->write($directory . '/schema.json', '{"$defs":{}}');

        $this->expectException(ReferenceResolutionException::class);
        $this->expectExceptionMessage('Invalid JSON Pointer escape');
        (new OpenApiLoader())->loadFile($directory . '/openapi.yaml');
    }

    public function testScalarReferenceTargetIsRejected(): void
    {
        $directory = $this->createDirectory();
        $this->write($directory . '/openapi.yaml', $this->baseSpec("components:\n  schemas:\n    Broken:\n      \$ref: './schema.json#/\$defs/Scalar'\n"));
        $this->write($directory . '/schema.json', '{"$defs":{"Scalar":"not an object"}}');

        $this->expectException(ReferenceResolutionException::class);
        $this->expectExceptionMessage('an object or array is required');
        (new OpenApiLoader())->loadFile($directory . '/openapi.yaml');
    }

    public function testRemoteUriReferenceIsRejected(): void
    {
        $root = $this->createSpec("components:\n  schemas:\n    Remote:\n      \$ref: 'https://example.com/schema.yaml#/User'\n");

        $this->expectException(ReferenceResolutionException::class);
        $this->expectExceptionMessage('Only local filesystem references are supported');
        (new OpenApiLoader())->loadFile($root);
    }

    public function testReferenceOutsideRootDirectoryIsRejectedByDefault(): void
    {
        $parent = $this->createDirectory();
        mkdir($parent . '/root');
        $this->write($parent . '/shared.yaml', "type: string\n");
        $this->write(
            $parent . '/root/openapi.yaml',
            $this->baseSpec("components:\n  schemas:\n    Shared:\n      \$ref: '../shared.yaml'\n"),
        );

        $this->expectException(ReferenceResolutionException::class);
        $this->expectExceptionMessage('outside the root document directory');
        (new OpenApiLoader())->loadFile($parent . '/root/openapi.yaml');
    }

    public function testReferenceOutsideRootDirectoryCanBeEnabled(): void
    {
        $parent = $this->createDirectory();
        mkdir($parent . '/root');
        $this->write($parent . '/shared.yaml', "type: string\n");
        $this->write(
            $parent . '/root/openapi.yaml',
            $this->baseSpec("components:\n  schemas:\n    Shared:\n      \$ref: '../shared.yaml'\n"),
        );

        $spec = (new OpenApiLoader(new LocalReferenceResolver(true)))->loadFile($parent . '/root/openapi.yaml');

        self::assertSame('string', $spec->components->schemas['Shared']->type);
    }

    public function testCircularExternalReferencesReportTheChain(): void
    {
        $directory = $this->createDirectory();
        $this->write($directory . '/openapi.yaml', $this->baseSpec("components:\n  schemas:\n    A:\n      \$ref: './a.yaml#/components/schemas/A'\n"));
        $this->write($directory . '/a.yaml', "components:\n  schemas:\n    A:\n      \$ref: './b.yaml#/components/schemas/B'\n");
        $this->write($directory . '/b.yaml', "components:\n  schemas:\n    B:\n      \$ref: './a.yaml#/components/schemas/A'\n");

        try {
            (new OpenApiLoader())->loadFile($directory . '/openapi.yaml');
            self::fail('Expected a circular reference error.');
        } catch (ReferenceResolutionException $exception) {
            self::assertStringContainsString('Circular external $ref detected', $exception->getMessage());
            self::assertStringContainsString('a.yaml#/components/schemas/A', $exception->getMessage());
            self::assertStringContainsString('b.yaml#/components/schemas/B', $exception->getMessage());
        }
    }

    public function testReferenceSiblingsAreRejectedExplicitly(): void
    {
        $root = $this->createSpec("components:\n  schemas:\n    Invalid:\n      \$ref: '#/components/schemas/Base'\n      description: ignored otherwise\n    Base:\n      type: string\n");

        $this->expectException(ReferenceResolutionException::class);
        $this->expectExceptionMessage('Unsupported $ref siblings');
        (new OpenApiLoader())->loadFile($root);
    }

    private function createSpec(string $extra): string
    {
        $directory = $this->createDirectory();
        $path = $directory . '/openapi.yaml';
        $this->write($path, $this->baseSpec($extra));

        return $path;
    }

    private function baseSpec(string $extra): string
    {
        return "openapi: 3.0.0\ninfo:\n  title: Test\n  version: 1.0.0\npaths: {}\n" . $extra;
    }

    private function createDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/openapi-ref-' . uniqid('', true);
        mkdir($directory);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function write(string $path, string $contents): void
    {
        self::assertNotFalse(file_put_contents($path, $contents));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
