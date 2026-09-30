<?php

declare(strict_types=1);

use App\Api\PetsApiClient;
use App\Model\NewPet;
use Symfony\Component\HttpClient\HttpClient;

$baseUrl = getenv('PETSTORE_BASE_URL');
if ($baseUrl === false || $baseUrl === '') {
    throw new RuntimeException('Set PETSTORE_BASE_URL to the running Petstore server URL.');
}

$clientDirectory = dirname(__DIR__, 2) . '/examples/petstore-client-symfony';
require $clientDirectory . '/vendor/autoload.php';

$client = new PetsApiClient(HttpClient::create(), rtrim($baseUrl, '/'));
$pets = $client->listPets(null);

if (count($pets) !== 2 || $pets[0]->name !== 'Fluffy' || $pets[1]->name !== 'Spot') {
    throw new RuntimeException('Generated client did not deserialize the expected Petstore response.');
}

$pet = $client->showPetById('1');
if ($pet->id !== 1 || $pet->name !== 'Fluffy') {
    throw new RuntimeException('Generated client did not deserialize the requested pet.');
}

$created = $client->createPet(new NewPet(name: 'Sparky', tag: 'dog'));
if ($created->name !== 'Sparky' || $created->tag !== 'dog') {
    throw new RuntimeException('Generated client did not create a pet through the server.');
}

$updated = $client->upsertPet('1', new NewPet(name: 'Ignored', tag: 'updated'));
if ($updated->id !== 1 || $updated->name !== 'Fluffy' || $updated->tag !== 'updated') {
    throw new RuntimeException('Generated client did not update a pet through the server.');
}

$client->deletePet('1');

fwrite(STDOUT, "Generated Petstore client smoke test passed.\n");
