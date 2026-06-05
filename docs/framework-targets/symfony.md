# Symfony Target

**FrameworkTarget::Symfony** generates framework-aware code optimized for Symfony applications:

- Interface defining all API operations
- **Abstract controller** with `#[Route]` attributes
- Full HTTP request/response handling 
- Symfony's dependency injection integration

If you need different generated glue for Symfony majors, set `$config->frameworkVersion` explicitly, for example `$config->frameworkVersion = '8.0';`.

## What Gets Generated

```
generated/
├── Api/
│   ├── PetsApiInterface.php      ← Interface defining all operations
│   └── PetsApiController.php     ← Abstract controller with #[Route]
└── Model/
    ├── Pet.php                   ← Full-featured models with serialization
    ├── NewPet.php
    ├── PetStatus.php             ← Enums for type constraints
    └── ApiError.php
```

## The Abstract Controller

The generated controller integrates with Symfony's routing and DI:

```php
namespace App\Api;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Model\NewPet;
use App\Model\Pet;

abstract class PetsApiController implements PetsApiInterface
{
    #[Route('/pets', methods: ['GET'])]
    public function listPetsAction(Request $request): JsonResponse
    {
        $result = $this->listPets(
            limit: $request->query->get('limit') ?? null,
        );
        return new JsonResponse($result, 200);
    }

    #[Route('/pets', methods: ['POST'])]
    public function createPetAction(Request $request): JsonResponse
    {
        $result = $this->createPet(
            body: NewPet::fromArray($request->toArray()),
        );
        return new JsonResponse($result->toArray(), 201);
    }

    // ... other operations

    // Abstract domain methods — you implement these
    abstract public function listPets(?int $limit): array;
    abstract public function createPet(NewPet $body): Pet;
    // ...
}
```

## Implementation Pattern

**Extend the abstract controller** and implement the domain methods:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\PetsApiController;
use App\Model\NewPet;
use App\Model\Pet;
use App\Repository\PetRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PetController extends PetsApiController
{
    public function __construct(private readonly PetRepository $repository)
    {
    }

    // Two implementation patterns available:

    // PATTERN 1: Implement domain method (simple case, 95% of operations)
    public function createPet(NewPet $body): Pet
    {
        return $this->repository->create($body->name, $body->tag);
    }

    public function listPets(?int $limit): array
    {
        return $this->repository->findAll($limit);
    }

    public function showPetById(string $petId): Pet
    {
        $pet = $this->repository->find((int) $petId);
        if ($pet === null) {
            throw new NotFoundHttpException("Pet $petId not found");
        }
        return $pet;
    }

    // PATTERN 2: Override *Action method for full HTTP control (edge case)
    #[\Override]
    public function upsertPetAction(Request $request): JsonResponse
    {
        // Custom logic with conditional status code
        $petId = (int) $request->attributes->get('petId');
        $body = NewPet::fromArray($request->toArray());
        $existing = $this->repository->find($petId);

        if ($existing !== null) {
            return new JsonResponse(
                $this->repository->update($existing, $body->tag)->toArray(),
                200 // Update
            );
        }

        return new JsonResponse(
            $this->repository->create($body->name, $body->tag)->toArray(),
            201 // Create
        );
    }

    // ... implement other domain methods ...

    public function deletePet(string $petId): void
    {
        $pet = $this->repository->find((int) $petId);
        if ($pet === null) {
            throw new NotFoundHttpException("Pet $petId not found");
        }
        $this->repository->delete($pet);
    }
}
```

## Integration with Symfony

### Automatic Route Registration

Routes are registered via `#[Route]` attributes. Just ensure your controller is discoverable:

**config/services.yaml:**
```yaml
services:
  App\Controller\:
    resource: '../src/Controller'
    tags: ['controller.service_arguments']
```

### Dependency Injection

Use Symfony's constructor injection for repositories and services:

```php
final class PetController extends PetsApiController
{
    public function __construct(
        private readonly PetRepository $repository,
        private readonly Logger $logger,
        private readonly EventDispatcher $events,
    ) {}
}
```

### Error Handling

Return appropriate HTTP exceptions for error cases:

```php
use Symfony\Component\HttpKernel\Exception\{
    BadRequestHttpException,
    NotFoundHttpException,
    UnprocessableEntityHttpException,
};

public function createPet(NewPet $body): Pet
{
    if (empty($body->name)) {
        throw new BadRequestHttpException('Name is required');
    }

    if ($this->repository->nameExists($body->name)) {
        throw new UnprocessableEntityHttpException('Pet name already exists');
    }

    return $this->repository->create($body->name, $body->tag);
}
```

### Custom Response Headers

Override the `*Action` method for custom headers:

```php
#[\Override]
public function listPetsAction(Request $request): JsonResponse
{
    $result = $this->listPets(
        limit: $request->query->get('limit') ?? null,
    );
    $response = new JsonResponse($result, 200);
    $response->headers->set('X-Total-Count', count($result));
    $response->headers->set('Cache-Control', 'max-age=3600');
    return $response;
}
```

## Best Practices

1. **Use constructor injection** for dependencies (repositories, services, loggers)
2. **Implement domain methods** (the 95% case) to keep code focused
3. **Override `*Action` methods** only when HTTP behavior conflicts with OpenAPI spec
4. **Throw HTTP exceptions** (`NotFoundHttpException`, `BadRequestHttpException`, etc.)
5. **Keep domain logic testable** — test domain methods independently from HTTP
6. **Use Symfony's event system** for cross-cutting concerns (logging, auditing, etc.)

## Testing

```php
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PetControllerTest extends WebTestCase
{
    public function testListPets(): void
    {
        $client = static::createClient();
        $client->request('GET', '/pets?limit=10');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testCreatePetWithoutName(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pets', [], [], ['CONTENT_TYPE' => 'application/json'], 
            json_encode(['tag' => 'fluffy']));

        $this->assertResponseStatusCodeSame(400);
    }
}
```

## See Also

- [Framework Targets Overview](../framework-targets.md)
- [Plain PHP (None Target)](./plain-php.md)
- [Laravel Target](./laravel.md)
- [Symfony Documentation](https://symfony.com/doc/current/controller.html)
