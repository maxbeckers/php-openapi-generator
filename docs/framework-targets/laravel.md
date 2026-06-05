# Laravel Target

**FrameworkTarget::Laravel** generates framework-aware code optimized for Laravel applications:

- Interface defining all API operations
- **Abstract controller** with Laravel request/response handling
- **Routes helper class** for easy route registration
- Full Illuminate HTTP integration

If you need different generated glue for Laravel majors, set `$config->frameworkVersion` explicitly, for example `$config->frameworkVersion = '11.0';`.

## What Gets Generated

```
generated/
├── Api/
│   ├── PetsApiInterface.php      ← Interface defining all operations
│   ├── PetsApiController.php     ← Abstract controller
│   └── PetsApiRoutes.php         ← Route registration helper
└── Model/
    ├── Pet.php                   ← Full-featured models with serialization
    ├── NewPet.php
    ├── PetStatus.php             ← Enums for type constraints
    └── ApiError.php
```

## The Abstract Controller

The generated controller integrates with Laravel's request/response handling:

```php
namespace App\Api;

use App\Model\NewPet;
use App\Model\Pet;

abstract class PetsApiController implements PetsApiInterface
{
    public function listPetsAction(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $result = $this->listPets(
            limit: $request->query('limit'),
        );
        return response()->json($result, 200);
    }

    public function createPetAction(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $result = $this->createPet(
            body: NewPet::fromArray($request->all()),
        );
        return response()->json($result->toArray(), 201);
    }

    public function showPetByIdAction(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $result = $this->showPetById(
            petId: $request->route('petId'),
        );
        return response()->json($result->toArray(), 200);
    }

    // ... other operations

    // Abstract domain methods — you implement these
    abstract public function listPets(?int $limit): array;
    abstract public function createPet(NewPet $body): Pet;
    // ...
}
```

## The Routes Helper

Easily register all routes for an API group:

```php
namespace App\Api;

use Illuminate\Support\Facades\Route;

final class PetsApiRoutes
{
    private function __construct()
    {
    }

    public static function register(string $controller = PetsApiController::class): void
    {
        Route::get('/pets', [$controller, 'listPetsAction']);
        Route::post('/pets', [$controller, 'createPetAction']);
        Route::get('/pets/{petId}', [$controller, 'showPetByIdAction']);
        Route::put('/pets/{petId}', [$controller, 'upsertPetAction']);
        Route::delete('/pets/{petId}', [$controller, 'deletePetAction']);
    }
}
```

## Implementation Pattern

**Extend the abstract controller** and implement the domain methods:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Api\PetsApiController;
use App\Model\NewPet;
use App\Model\Pet;
use App\Repository\PetRepository;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

final class PetController extends PetsApiController
{
    public function __construct(private readonly PetRepository $repository)
    {
    }

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
            abort(404, "Pet $petId not found");
        }
        return $pet;
    }

    // PATTERN 2: Override *Action method for full HTTP control (edge case)
    #[\Override]
    public function upsertPetAction(Request $request): JsonResponse
    {
        // Custom logic with conditional status code
        $petId = $request->route('petId');
        $body = NewPet::fromArray($request->all());
        $existing = $this->repository->find((int) $petId);

        if ($existing !== null) {
            return response()->json(
                $this->repository->update($existing, $body->tag)->toArray(),
                200 // Update
            );
        }

        return response()->json(
            $this->repository->create($body->name, $body->tag)->toArray(),
            201 // Create
        );
    }

    // ... implement other domain methods ...

    public function deletePet(string $petId): void
    {
        $pet = $this->repository->find((int) $petId);
        if ($pet === null) {
            abort(404, "Pet $petId not found");
        }
        $this->repository->delete($pet);
    }
}
```

## Route Registration

### Option A: Use the Generated Routes Helper

In `routes/api.php`:

```php
<?php

use App\Api\PetsApiRoutes;
use App\Http\Controllers\Api\PetController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->group(function () {
    PetsApiRoutes::register(PetController::class);
});
```

### Option B: Manual Route Registration

```php
<?php

use App\Http\Controllers\Api\PetController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->group(function () {
    Route::get('/pets', [PetController::class, 'listPetsAction']);
    Route::post('/pets', [PetController::class, 'createPetAction']);
    Route::get('/pets/{petId}', [PetController::class, 'showPetByIdAction']);
    Route::put('/pets/{petId}', [PetController::class, 'upsertPetAction']);
    Route::delete('/pets/{petId}', [PetController::class, 'deletePetAction']);
});
```

## Integration with Laravel

### Dependency Injection via Container

Use Laravel's service container for automatic injection:

```php
final class PetController extends PetsApiController
{
    public function __construct(
        private readonly PetRepository $repository,
        private readonly Logger $logger,
    ) {}
}
```

### Error Handling

Use `abort()` or exception handling with Laravel's exception handler:

```php
public function createPet(NewPet $body): Pet
{
    if (empty($body->name)) {
        abort(400, 'Name is required');
    }

    if ($this->repository->nameExists($body->name)) {
        abort(422, 'Pet name already exists');
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
        limit: $request->query('limit'),
    );
    return response()
        ->json($result, 200)
        ->header('X-Total-Count', count($result))
        ->header('Cache-Control', 'max-age=3600');
}
```

### Request Validation

```php
use Illuminate\Support\Facades\Validator;

public function createPet(NewPet $body): Pet
{
    // Validation happens before reaching domain method
    // But you can add defensive checks:
    
    if (!$this->isValidName($body->name)) {
        abort(400, 'Invalid pet name');
    }

    return $this->repository->create($body->name, $body->tag);
}
```

### Events & Listeners

Leverage Laravel's event system:

```php
use App\Events\PetCreated;

public function createPet(NewPet $body): Pet
{
    $pet = $this->repository->create($body->name, $body->tag);
    
    event(new PetCreated($pet));
    
    return $pet;
}
```

### Database Transactions

```php
use Illuminate\Support\Facades\DB;

public function createPet(NewPet $body): Pet
{
    return DB::transaction(function () use ($body) {
        return $this->repository->create($body->name, $body->tag);
    });
}
```

## Best Practices

1. **Use constructor injection** for dependencies (repositories, services, loggers)
2. **Implement domain methods** (the 95% case) to keep code focused
3. **Override `*Action` methods** only when HTTP behavior conflicts with OpenAPI spec
4. **Use `abort()` or throw exceptions** for error cases
5. **Keep domain logic testable** — test domain methods independently from HTTP
6. **Use Laravel's event system** for cross-cutting concerns (logging, auditing, etc.)
7. **Leverage query builders** and Eloquent models for data access

## Testing

```php
use Tests\TestCase;

class PetControllerTest extends TestCase
{
    public function test_list_pets(): void
    {
        $response = $this->getJson('/api/pets?limit=10');

        $response->assertStatus(200)
                 ->assertJsonIsArray();
    }

    public function test_create_pet_without_name(): void
    {
        $response = $this->postJson('/api/pets', ['tag' => 'fluffy']);

        $response->assertStatus(400);
    }

    public function test_delete_nonexistent_pet(): void
    {
        $response = $this->deleteJson('/api/pets/999');

        $response->assertStatus(404);
    }
}
```

## API Documentation

Serve your OpenAPI spec and use:

- **Scalar** — Beautiful interactive API documentation
- **Swagger UI** — Standard OpenAPI UI
- **ReDoc** — Clean, modern documentation
- **Stoplight** — High-fidelity REST API documentation

In `routes/web.php`:

```php
Route::get('/docs', function () {
    return view('swagger', [
        'spec' => asset('api/openapi.yaml'),
    ]);
});
```

## See Also

- [Framework Targets Overview](../framework-targets.md)
- [Plain PHP (None Target)](./plain-php.md)
- [Symfony Target](./symfony.md)
- [Laravel Documentation](https://laravel.com/docs/controllers)
