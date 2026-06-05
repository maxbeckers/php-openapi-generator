<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Unit\Loader;

use MaxBeckers\OpenApiGenerator\Loader\OpenApiLoader;
use MaxBeckers\OpenApiGenerator\Spec\OpenApiSpec;
use PHPUnit\Framework\TestCase;

class OpenApiLoaderTest extends TestCase
{
    private OpenApiLoader $loader;
    private string $petstoreFixture;

    protected function setUp(): void
    {
        $this->loader = new OpenApiLoader();
        $this->petstoreFixture = __DIR__ . '/../../Fixtures/petstore.yaml';
    }

    public function testLoadFileProducesOpenApiSpec(): void
    {
        $spec = $this->loader->loadFile($this->petstoreFixture);

        self::assertInstanceOf(OpenApiSpec::class, $spec);
        self::assertSame('3.0.0', $spec->openapi);
    }

    public function testInfoIsHydrated(): void
    {
        $spec = $this->loader->loadFile($this->petstoreFixture);

        self::assertSame('Petstore', $spec->info->title);
        self::assertSame('1.0.0', $spec->info->version);
        self::assertSame('A sample petstore API', $spec->info->description);
    }

    public function testServersAreHydrated(): void
    {
        $spec = $this->loader->loadFile($this->petstoreFixture);

        self::assertCount(1, $spec->servers);
        self::assertSame('https://petstore.example.com/v1', $spec->servers[0]->url);
        self::assertSame('Production', $spec->servers[0]->description);
    }

    public function testTagsAreHydrated(): void
    {
        $spec = $this->loader->loadFile($this->petstoreFixture);

        self::assertCount(1, $spec->tags);
        self::assertSame('pets', $spec->tags[0]->name);
    }

    public function testComponentsSchemasAreHydrated(): void
    {
        $spec = $this->loader->loadFile($this->petstoreFixture);

        self::assertArrayHasKey('Pet', $spec->components->schemas);
        self::assertArrayHasKey('NewPet', $spec->components->schemas);
        self::assertArrayHasKey('Pets', $spec->components->schemas);
        self::assertArrayHasKey('PetStatus', $spec->components->schemas);
        self::assertArrayHasKey('ApiError', $spec->components->schemas);
    }

    public function testPetSchemaProperties(): void
    {
        $spec = $this->loader->loadFile($this->petstoreFixture);
        $pet = $spec->components->schemas['Pet'];

        self::assertSame('object', $pet->type);
        self::assertSame(['id', 'name'], $pet->required);
        self::assertArrayHasKey('id', $pet->properties);
        self::assertArrayHasKey('name', $pet->properties);
        self::assertArrayHasKey('tag', $pet->properties);
        self::assertArrayHasKey('status', $pet->properties);
    }

    public function testPetSchemaPropertyTypes(): void
    {
        $spec = $this->loader->loadFile($this->petstoreFixture);
        $pet = $spec->components->schemas['Pet'];

        self::assertSame('integer', $pet->properties['id']->type);
        self::assertSame('int64', $pet->properties['id']->format);
        self::assertTrue($pet->properties['id']->readOnly);

        self::assertSame('string', $pet->properties['name']->type);
        self::assertSame(1, $pet->properties['name']->minLength);
        self::assertSame(100, $pet->properties['name']->maxLength);

        self::assertTrue($pet->properties['tag']->nullable);
    }

    public function testPetStatusIsEnum(): void
    {
        $spec = $this->loader->loadFile($this->petstoreFixture);
        $status = $spec->components->schemas['PetStatus'];

        self::assertSame('string', $status->type);
        self::assertSame(['available', 'pending', 'sold'], $status->enum);
    }

    public function testPetsIsArraySchema(): void
    {
        $spec = $this->loader->loadFile($this->petstoreFixture);
        $pets = $spec->components->schemas['Pets'];

        self::assertSame('array', $pets->type);
        self::assertNotNull($pets->items);
        // items keeps the $ref string; type resolution happens in the generator
        self::assertSame('#/components/schemas/Pet', $pets->items->ref);
    }

    public function testPathsAreHydrated(): void
    {
        $spec = $this->loader->loadFile($this->petstoreFixture);

        self::assertArrayHasKey('/pets', $spec->paths);
        self::assertArrayHasKey('/pets/{petId}', $spec->paths);
    }

    public function testGetOperationOnPetsPath(): void
    {
        $spec = $this->loader->loadFile($this->petstoreFixture);
        $petsPath = $spec->paths['/pets'];

        self::assertNotNull($petsPath->get);
        self::assertSame('listPets', $petsPath->get->operationId);
        self::assertSame(['pets'], $petsPath->get->tags);
    }

    public function testPostOperationHasRequestBody(): void
    {
        $spec = $this->loader->loadFile($this->petstoreFixture);
        $op = $spec->paths['/pets']->post;

        self::assertNotNull($op);
        self::assertSame('createPet', $op->operationId);
        self::assertNotNull($op->requestBody);
        self::assertTrue($op->requestBody->required);
        self::assertArrayHasKey('application/json', $op->requestBody->content);
    }

    public function testPathLevelParameterMergedIntoOperation(): void
    {
        $spec = $this->loader->loadFile($this->petstoreFixture);
        // /pets/{petId} has a path-level `petId` param; GET and DELETE should both carry it
        $getOp = $spec->paths['/pets/{petId}']->get;

        self::assertNotNull($getOp);
        $paramNames = array_map(fn ($p) => $p->name, $getOp->parameters);
        self::assertContains('petId', $paramNames);
    }

    public function testDeleteOperationExists(): void
    {
        $spec = $this->loader->loadFile($this->petstoreFixture);
        $deleteOp = $spec->paths['/pets/{petId}']->delete;

        self::assertNotNull($deleteOp);
        self::assertSame('deletePet', $deleteOp->operationId);
        // 204 response has no content
        self::assertArrayHasKey('204', $deleteOp->responses);
    }

    public function testQueryParameterOnListPets(): void
    {
        $spec = $this->loader->loadFile($this->petstoreFixture);
        $params = $spec->paths['/pets']->get->parameters;

        self::assertCount(1, $params);
        self::assertSame('limit', $params[0]->name);
        self::assertSame('query', $params[0]->in);
        self::assertFalse($params[0]->required);
        self::assertNotNull($params[0]->schema);
        self::assertSame(1, $params[0]->schema->minimum);
        self::assertSame(100, $params[0]->schema->maximum);
    }

    public function testResponseSchemaIsHydrated(): void
    {
        $spec = $this->loader->loadFile($this->petstoreFixture);
        $response = null;
        foreach ($spec->paths['/pets']->get->responses as $statusCode => $candidate) {
            if ((string) $statusCode === '200') {
                $response = $candidate;

                break;
            }
        }

        self::assertNotNull($response);
        self::assertArrayHasKey('application/json', $response->content);
        // After ref resolution the schema is the Pets array schema
        $schema = $response->content['application/json']->schema;
        self::assertNotNull($schema);
    }

    public function testNonExistentFileThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->loader->loadFile('/does/not/exist.yaml');
    }

    public function testHyphenatedExtensionsAreParsedFromYamlFile(): void
    {
        $yaml = <<<'YAML'
openapi: 3.0.0
info:
  title: T
  version: 1
paths: {}
components:
  schemas:
    PluginSchema:
      type: object
      properties:
        name:
          type: string
          x-trim: 30
        password:
          type: string
          x-sensitive: true
YAML;

        $file = tempnam(sys_get_temp_dir(), 'oas-yaml-');
        self::assertNotFalse($file);

        try {
            file_put_contents($file, $yaml);
            $spec = $this->loader->loadFile($file);

            $name = $spec->components->schemas['PluginSchema']->properties['name'];
            self::assertSame('string', $name->type);
            self::assertSame(30, $name->extensions['x-trim'] ?? null);

            $password = $spec->components->schemas['PluginSchema']->properties['password'];
            self::assertSame('string', $password->type);
            self::assertTrue((bool) ($password->extensions['x-sensitive'] ?? false));
        } finally {
            @unlink($file);
        }
    }
}
