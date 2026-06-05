<?php

/**
 * Stub PetRepository — illustrates the interface expected by both example controllers.
 *
 * In a real Symfony app this would be a Doctrine repository or similar. This
 * stub is here purely to make the example self-contained and IDE-friendly.
 */

declare(strict_types=1);

namespace App\Repository;

use App\Model\Pet;

class PetRepository
{
    /**
     * @return Pet[]
     */
    public function findAll(?int $limit = null): array
    {
        return [
            new Pet(id: 1, name: 'Fluffy', tag: 'cat'),
            new Pet(id: 2, name: 'Spot', tag: 'dog'),
        ];
    }

    public function find(int $id): ?Pet
    {
        return match ($id) {
            1 => new Pet(id: 1, name: 'Fluffy', tag: 'cat'),
            2 => new Pet(id: 2, name: 'Spot', tag: 'dog'),
            default => null,
        };
    }

    public function create(string $name, ?string $tag): Pet
    {
        return new Pet(id: random_int(3, 1000), name: $name, tag: $tag);
    }

    public function update(Pet $pet, ?string $tag): Pet
    {
        return new Pet(id: $pet->id, name: $pet->name, tag: $tag);
    }

    public function delete(Pet $pet): void
    {
        // nothing to do in the dummy
    }
}
