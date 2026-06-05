# Plain PHP - FrameworkTarget::None

**FrameworkTarget::None** generates framework-agnostic code: pure PHP interfaces and typed models without any coupling to Symfony, Laravel, or other frameworks.

## What Gets Generated

With **FrameworkTarget::None**, you receive:

```
generated/
├── Api/
│   └── PetsApiInterface.php      ← Interface defining all operations
└── Model/
    ├── Pet.php                   ← Full-featured models with serialization
    ├── NewPet.php
    ├── PetStatus.php             ← Enums for type constraints
    └── ApiError.php
```

## The Interface

The generated interface specifies the **contract** for your API operations:

```php
namespace App\Api;

interface PetsApiInterface
{
    /** List all pets */
    public function listPets(?int $limit): array;

    /** Create a pet */
    public function createPet(NewPet $body): Pet;

    /** Info for a specific pet */
    public function showPetById(string $petId): Pet;

    /** Upsert a pet by id */
    public function upsertPet(string $petId, NewPet $body): Pet;

    /** Delete a pet */
    public function deletePet(string $petId): void;
}
```

## Generated Models

All models are generated as **readonly classes** (PHP 8.2+), providing immutability at the class level:

```php
readonly class Pet
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $tag = null,
    ) {}

    public static function fromArray(array $data): static
    {
        return new static(
            id: $data['id'] ?? 0,
            name: $data['name'] ?? '',
            tag: $data['tag'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tag' => $this->tag,
        ];
    }
}
```

**Key Features:**

- **Immutable by default** — All public properties are readonly
- **Constructor promotion** — Properties automatically assigned from constructor parameters
- **Serialization** — Easy conversion to/from arrays for JSON APIs
- **Type safety** — Full PHP type hints including nullable and union types
- **Enums** — OpenAPI type constraints become PHP enums

For more details on readonly classes, see [Readonly Classes](../readonly-classes.md).

## Implementation Patterns

### Pattern 1: Domain-Only Implementation (Recommended)

Implement only the **domain logic** — the interface methods. Let custom HTTP routing handle request/response concerns separately.

```php
namespace App\Api;

use App\Model\NewPet;
use App\Model\Pet;

final class PetsApi implements PetsApiInterface
{
    public function __construct(private PetRepository $repository) {}

    public function listPets(?int $limit): array
    {
        $pets = $this->repository->findAll($limit);
        return array_map(fn($p) => $p->toArray(), $pets);
    }

    public function createPet(NewPet $body): Pet
    {
        return $this->repository->create($body->name, $body->tag);
    }

    public function showPetById(string $petId): Pet
    {
        $pet = $this->repository->find((int) $petId);
        if ($pet === null) {
            throw new \Exception("Pet $petId not found", 404);
        }
        return $pet;
    }

    public function upsertPet(string $petId, NewPet $body): Pet
    {
        $existing = $this->repository->find((int) $petId);
        if ($existing !== null) {
            return $this->repository->update($existing, $body->tag);
        }
        return $this->repository->create($body->name, $body->tag);
    }

    public function deletePet(string $petId): void
    {
        $pet = $this->repository->find((int) $petId);
        if ($pet === null) {
            throw new \Exception("Pet $petId not found", 404);
        }
        $this->repository->delete($pet);
    }
}
```

**Advantages:**
- Pure domain code, easily unit-testable
- No framework dependencies
- Can be used with any HTTP framework, router, or microframework
- Simple to reason about and maintain

### Pattern 2: HTTP Handler (Advanced)

For edge cases requiring custom HTTP behavior (conditional status codes, streaming, etc.), implement custom handlers separate from domain logic:

```php
namespace App\Http;

use App\Api\PetsApi;
use App\Model\NewPet;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\RequestInterface;

final class PetsHandler
{
    public function __construct(private PetsApi $api) {}

    public function upsertPet(RequestInterface $request, array $params): ResponseInterface
    {
        $petId = $params['petId'];
        $body = NewPet::fromArray($this->getRequestBody($request));

        $pet = $this->api->upsertPet($petId, $body);

        // Custom logic: return 200 for update, 201 for create
        // (We'd need to track this in the domain logic somehow)
        $statusCode = $this->wasUpdated ? 200 : 201;

        return $this->jsonResponse($pet->toArray(), $statusCode);
    }

    private function getRequestBody(RequestInterface $request): array
    {
        return json_decode($request->getBody()->getContents(), true) ?? [];
    }

    private function jsonResponse(mixed $data, int $status): ResponseInterface
    {
        // Return appropriate PSR-7 response
        // (e.g., using Laminas\Diactoros\Response\JsonResponse)
    }
}
```

## Routing Approaches

Since there's no built-in routing, choose an approach that fits your needs:

### Option A: Manual Routing (Simple Projects)

```php
<?php
// index.php or router.php

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$api = new App\Api\PetsApi($repository);

try {
    if ($method === 'GET' && preg_match('/^\/pets$/', $path)) {
        $limit = $_GET['limit'] ?? null;
        $result = $api->listPets($limit);
        header('Content-Type: application/json');
        echo json_encode($result);
        exit(0);
    }

    if ($method === 'GET' && preg_match('/^\/pets\/(\w+)$/', $path, $matches)) {
        $petId = $matches[1];
        $result = $api->showPetById($petId);
        header('Content-Type: application/json');
        echo json_encode($result->toArray());
        exit(0);
    }

    if ($method === 'POST' && preg_match('/^\/pets$/', $path)) {
        $body = json_decode(file_get_contents('php://input'), true);
        $newPet = App\Model\NewPet::fromArray($body);
        $result = $api->createPet($newPet);
        header('Content-Type: application/json');
        http_response_code(201);
        echo json_encode($result->toArray());
        exit(0);
    }

    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
} catch (Exception $e) {
    http_response_code((int)($e->getCode() ?: 500));
    echo json_encode(['error' => $e->getMessage()]);
}
```

**Advantages:**
- No external dependencies
- Easy to understand
- Fine for simple APIs

**Disadvantages:**
- Becomes unwieldy with many endpoints
- Error handling gets repetitive

### Option B: FastRoute (Lightweight Router)

For more complex APIs, use a dedicated router library like **FastRoute**:

```bash
composer require nikic/fast-route
```

```php
<?php
// bootstrap/router.php

use FastRoute\RouteCollector;
use FastRoute\Dispatcher;

return FastRoute\simpleDispatcher(function (RouteCollector $r) {
    $r->get('/pets', ['App\Api\PetsApi', 'listPets']);
    $r->post('/pets', ['App\Api\PetsApi', 'createPet']);
    $r->get('/pets/{petId}', ['App\Api\PetsApi', 'showPetById']);
    $r->put('/pets/{petId}', ['App\Api\PetsApi', 'upsertPet']);
    $r->delete('/pets/{petId}', ['App\Api\PetsApi', 'deletePet']);
});

// In your entry point (index.php):
$dispatcher = require 'bootstrap/router.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$route = $dispatcher->dispatch($method, $path);

switch ($route[0]) {
    case Dispatcher::FOUND:
        [$handler, $action] = $route[1];
        $params = $route[2];

        try {
            $container = new Container(); // dependency injection
            $api = $container->get($handler);

            if ($method === 'POST' || $method === 'PUT') {
                $body = json_decode(file_get_contents('php://input'), true);
                $params['body'] = $body;
            }

            $result = call_user_func([$api, $action], ...$params);

            header('Content-Type: application/json');
            echo json_encode($result instanceof Model ? $result->toArray() : $result);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case Dispatcher::NOT_FOUND:
        http_response_code(404);
        echo json_encode(['error' => 'Route not found']);
        break;

    case Dispatcher::METHOD_NOT_ALLOWED:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}
```

### Option C: PSR-15 Middleware Stack

For middleware-oriented architectures, use PSR-15:

```php
<?php
// Use any PSR-15 framework: Slim, Spiral, Leaf, etc.

// Slim 4 example:
$app = new \Slim\Slim();
$container = $app->getContainer();

$container->set(PetRepository::class, function () {
    return new PetRepository(); // your repository
});

$container->set(PetsApi::class, function ($c) {
    return new PetsApi($c->get(PetRepository::class));
});

// Bind routes to API methods
$app->get('/pets', function (Request $request, Response $response) use ($container) {
    $api = $container->get(PetsApi::class);
    $limit = $request->getQueryParams()['limit'] ?? null;

    $pets = $api->listPets($limit);
    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200)
        ->write(json_encode($pets));
});

$app->get('/pets/{petId}', function (Request $request, Response $response) use ($container) {
    $api = $container->get(PetsApi::class);
    $petId = $request->getAttribute('petId');

    try {
        $pet = $api->showPetById($petId);
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200)
            ->write(json_encode($pet->toArray()));
    } catch (Exception $e) {
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(404)
            ->write(json_encode(['error' => 'Not found']));
    }
});

// ... etc
$app->run();
```

## Request Parameter Handling

### Query Parameters

```php
$limit = (int) ($_GET['limit'] ?? 10);
$offset = (int) ($_GET['offset'] ?? 0);

// Or from router params:
$limit = (int) $params['limit'] ?? 10;
```

### Path Parameters

```php
// Manual regex:
if (preg_match('/^\/pets\/(\w+)$/', $path, $matches)) {
    $petId = $matches[1];
}

// Or router provides them:
$petId = $params['petId'];
```

### Request Body

```php
$body = json_decode(file_get_contents('php://input'), true);
$newPet = App\Model\NewPet::fromArray($body);

// With validation (example):
if (empty($body['name'])) {
    throw new Exception('Field "name" is required', 400);
}
```

## Response Formatting

### JSON Response

```php
header('Content-Type: application/json');

// From model:
$pet = $api->getPet($id);
echo json_encode($pet->toArray());

// Array of models:
$pets = $api->listPets();
echo json_encode(array_map(fn ($p) => $p->toArray(), $pets));

// Error:
http_response_code(400);
echo json_encode(['error' => 'Invalid request']);
```

### HTTP Status Codes

The OpenAPI spec defines success and error status codes per operation. Here's a typical pattern:

```php
try {
    $result = $api->createPet($newPet);
    http_response_code(201);           // Created
    echo json_encode($result->toArray());
} catch (DuplicateException $e) {
    http_response_code(409);           // Conflict
    echo json_encode(['error' => $e->getMessage()]);
} catch (ValidationException $e) {
    http_response_code(400);           // Bad Request
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);           // Internal Server Error
    echo json_encode(['error' => 'Server error']);
}
```

## Best Practices

1. **Keep domain logic separate** from HTTP concerns
   - Domain logic in interface implementation
   - HTTP handling (routing, serialization) separate

2. **Use Dependency Injection**
   - Inject repositories, services, etc. into your API class
   - Makes testing easier

3. **Handle errors gracefully**
   - Catch exceptions with appropriate HTTP status codes
   - Return consistent error format

4. **Validate early**
   - Check query/path parameters at routing level
   - Check body structure in HTTP handlers

5. **Use typed models**
   - Leverage the generated models' `fromArray()` and `toArray()`
   - Rely on PHP 8.2 type hints for safety

6. **Document your routing**
   - Whether manual or library-based, keep routing configuration clear

## Example: Complete Minimal App

See the `petstore-server` example directory for a complete implementation using manual routing.

## Adding Documentation

To document your generated API (similar to OpenAPI specs), consider:

- **Scalar** — Beautiful API documentation viewer
- **Swagger UI** — Standard OpenAPI UI
- **ReDoc** — Clean, modern API documentation
- **Stoplight** — High-fidelity REST API documentation

Simply serve your original OpenAPI YAML/JSON spec and point documentation generators at it.

## Migration Path

If you start with **FrameworkTarget::None** and later need Symfony or Laravel:

1. Keep your domain implementations (interface implementations)
2. Regenerate with the target framework
3. Update your concrete controller classes to extend the new abstract controller
4. Minimal changes needed outside the API layer

This is why separating domain logic (domain methods) from HTTP handler is recommended — makes migration smooth.
