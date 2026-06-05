# Petstore — Symfony Server Example

This example shows how to use the generated server-side code in a Symfony application.

## Structure

```
openapi.yaml              ← the OpenAPI spec (source of truth)
generate.php              ← run this to regenerate API + Model classes
generated/
    Api/
        PetsApiInterface.php      ← interface the controller must satisfy
        PetsApiController.php     ← abstract controller with routing + HTTP glue
    Model/
        Pet.php, NewPet.php, PetStatus.php, ApiError.php
src/
    Controller/
        PetController.php         ← Pattern 1 + Pattern 2 in one controller
    Repository/
        PetRepository.php         ← your own repository interface (not generated)
```

## How it works

The generator produces two layers:

```
PetsApiInterface          ← pure PHP contract (typed input/output, no HTTP)
    ↑ implements
PetsApiController         ← abstract class with #[Route] actions + default stubs
    ↑ extends
YourController            ← you implement only what you need
```

## Pattern 1 — Standard (95% of cases)

Extend the abstract controller and implement only the domain methods. The generated
`*Action()` methods handle routing, deserialization, serialization, and status codes.

```php
final class PetController extends PetsApiController
{
    public function showPetById(string $petId): Pet
    {
        $pet = $this->repository->find((int) $petId);
        if ($pet === null) {
            throw new NotFoundHttpException("Pet $petId not found.");
        }
        return $pet;  // generated Action wraps this in JsonResponse(200)
    }

    public function deletePet(string $petId): void
    {
        // ...
        $this->repository->delete($pet);
        // generated Action returns JsonResponse(null, 204)
    }
}
```

**Error handling:** throw standard Symfony HTTP exceptions —
`NotFoundHttpException` (404), `AccessDeniedHttpException` (403), etc. Symfony's
kernel exception listener converts them to JSON responses automatically.

## Pattern 2 — Override the *Action method (edge cases)

When you need full control over the response — different status codes per branch,
custom headers, streaming — override the `*Action` method directly.

```php
final class PetController extends PetsApiController
{
    #[\Override]
    public function upsertPetAction(Request $request): JsonResponse
    {
        $petId = (int) $request->attributes->get('petId');
        $body = NewPet::fromArray($request->toArray());
        $existing = $this->repository->find($petId);

        if ($existing !== null) {
            return new JsonResponse($this->repository->update($existing, $body->tag)->toArray(), 200);
        }
        return new JsonResponse($this->repository->create($body->name, $body->tag)->toArray(), 201);
    }

    // upsertPet() domain method is never called — no need to implement it.
    // The generated BadMethodCallException stub is the safe default.
}
```

## Regenerating after spec changes

```bash
php generate.php
# or let the Composer plugin auto-generate on install/update/dump-autoload
composer.phar update
```

Files in `generated/` are always overwritten. Files in `src/` are yours — they are
never touched by the generator.

## Status codes

Status codes come from your OpenAPI spec, not from hardcoded values:
- `POST /pets` → `201 Created`  (defined in `openapi.yaml` responses."201")
- `PUT /pets/{petId}` → can return `200` or `201` when you override `upsertPetAction()`
- `DELETE /pets/{petId}` → `204 No Content`
- Everything else → the first 2xx code in the spec, defaulting to `200`
