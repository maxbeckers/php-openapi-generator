<?php

/**
 * This file shows BOTH implementation patterns in a single controller.
 *
 * PATTERN 1 — Standard domain-method override (the 95% case)
 * -----------------------------------------------------------
 * Implement only the pure business-logic method. The generated *Action() method
 * handles all HTTP concerns: routing, deserialization, serialization, status code.
 * You focus entirely on the domain: typed input → typed output.
 *
 * PATTERN 2 — Override the *Action method (the edge case)
 * -------------------------------------------------------
 * Use this when the generated HTTP glue is not flexible enough for a specific
 * operation. You take full control of the request/response cycle.
 *
 * In this example, PUT /pets/{petId} uses Pattern 2 for upsert semantics:
 *   - petId exists  → update and return 200 OK
 *   - petId missing → create and return 201 Created
 *
 * The generated upsertPetAction() always uses one configured success status,
 * so we override it to branch between 200 and 201.
 * Note: when overriding *Action(), you own the HTTP layer — call your own
 * repository/service methods directly; you don't need to call upsertPet().
 *
 * USAGE
 *   Register this class as a Symfony controller service (autowired by default
 *   when placed under the src/ directory configured in services.yaml).
 */

declare(strict_types=1);

namespace App\Controller;

use App\Api\Api\PetsApiController;
use App\Model\NewPet;
use App\Model\Pet;
use App\Repository\PetRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// generated abstract controller

final class PetController extends PetsApiController
{
    public function __construct(private readonly PetRepository $repository)
    {
    }

    // =========================================================================
    // PATTERN 2 — upsertPetAction: PUT upsert with conditional 200/201 status
    //
    // The generated upsertPetAction() returns a fixed success code from the spec.
    // Here we need 200 for update and 201 for create, so we override the action.
    // The upsertPet() domain stub is never called — that's intentional.
    // =========================================================================

    #[\Override]
    public function upsertPetAction(Request $request): JsonResponse
    {
        $petId = (int) $request->attributes->get('petId');
        $body = NewPet::fromArray($request->toArray());
        $existing = $this->repository->find($petId);

        if ($existing !== null) {
            // Update existing pet — 200 OK
            return new JsonResponse($this->repository->update($existing, $body->tag)->toArray(), 200);
        }

        // Create new pet — 201 Created
        return new JsonResponse($this->repository->create($body->name, $body->tag)->toArray(), 201);
    }

    // =========================================================================
    // PATTERN 1 — remaining operations: implement domain method, let the
    // generated *Action handle routing + serialization + status code
    // =========================================================================

    public function createPet(NewPet $body): Pet
    {
        return $this->repository->create($body->name, $body->tag);
    }

    /** @return Pet[] */
    public function listPets(?int $limit): array
    {
        return $this->repository->findAll($limit);
    }

    public function showPetById(string $petId): Pet
    {
        $pet = $this->repository->find((int) $petId);

        if ($pet === null) {
            throw new NotFoundHttpException("Pet $petId not found.");
        }

        return $pet;
    }

    public function deletePet(string $petId): void
    {
        $pet = $this->repository->find((int) $petId);

        if ($pet === null) {
            throw new NotFoundHttpException("Pet $petId not found.");
        }

        $this->repository->delete($pet);
    }
}
