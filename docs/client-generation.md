# Client Generation

Use `GenerationTarget::Client` to generate typed API client classes from `paths` plus DTO models from `components/schemas`.

This keeps API client generation in a PHP-native workflow: no npm-based generators required.

## What Gets Generated

For each API group, the generator creates:

- `*ApiClientInterface` for mocking and test seams
- `*ApiClient` concrete implementation
- referenced model classes/enums under your model output directory

Typical output:

```text
generated/
|- Api/
|  |- PetsApiClientInterface.php
|  `- PetsApiClient.php
`- Model/
   |- Pet.php
   |- NewPet.php
   `- ...
```

## Basic Configuration

```php
<?php

declare(strict_types=1);

use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;
use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Config\HttpClientAdapter;

$config = new GeneratorConfig();

$config->specFile = 'openapi.yaml';
$config->outputDir = 'generated';
$config->generationTarget = GenerationTarget::Client;
$config->httpClient = HttpClientAdapter::SymfonyHttpClient;

$config->modelNamespace = 'App\\Model';
$config->apiNamespace = 'App\\Api';

return $config;
```

## Runtime Shape of Generated Clients

Generated client methods usually follow this flow:

1. Build URL from `baseUrl` (trailing slashes are trimmed) and URL-encoded path values.
2. Build the query string and body payload from typed inputs.
3. Execute HTTP request with selected adapter.
4. Decode the JSON response and map it back into generated DTOs via `fromResponseArray()`.

Shared behavior:

- nullable query parameters are omitted; booleans are sent as `true`/`false`,
  enums by their backing value
- array query parameters follow OpenAPI `style`/`explode`: `form` + explode
  (default, `tag=a&tag=b`), `form` without explode (`ids=1,2`),
  `spaceDelimited` (`a%20b`), `pipeDelimited` (`a%7Cb`) and `deepObject`
- header parameters are sent as request headers and cookie parameters are
  URL-encoded into the `Cookie` header
- request DTOs are serialized with `toRequestArray()` as JSON
  (`Content-Type: application/json`)
- request bodies must declare `application/json`; other request media types
  (including multipart, form-urlencoded, octet-stream and text/plain) are
  rejected during generation rather than emitted with incorrect JSON handling
- array responses are mapped item by item
- `204`/no-content operations return `void`; if an operation mixes no-content
  and JSON success responses, the method returns `null` for the no-content status
- multiple 2xx responses with different models return a union type hydrated by
  status code
- if an operation declares no 2xx response, the `default` response describes
  the success payload
- non-2xx responses throw a `RuntimeException` identifying the operation and
  HTTP status code (see [Typed Error Responses](#typed-error-responses))
- non-JSON content types, invalid JSON and unexpected JSON shapes throw an
  `UnexpectedValueException` identifying the operation
- `format: date` / `format: date-time` parameters are typed
  `\DateTimeInterface` and sent as `Y-m-d` / RFC 3339

## Authentication

With `$config->generateSecuritySchemes = true` (default), `components.securitySchemes`
are applied to operations. An `ApiCredentials` class is generated next to the
clients, and clients whose operations are secured accept it as an optional
last constructor argument:

```php
$client = new PetsApiClient($httpClient, 'https://api.example.com', credentials: new ApiCredentials(
    bearerAuthToken: $token,        // http bearer, oauth2, openIdConnect: {scheme}Token
    basicAuthUsername: 'user',      // http basic: {scheme}Username / {scheme}Password
    basicAuthPassword: 'secret',
    apiKey: 'key',                  // apiKey (header, query or cookie): {scheme}
));
```

Operation-level `security` overrides the global requirement and `security: []`
disables authentication. For each request, the first requirement alternative
whose credentials are all set is applied; if none matches (or `{}` is listed),
the request is sent without credentials.

## Typed Error Responses

With `$config->typedErrorResponses = true`, non-2xx responses throw
`{apiNamespace}\Exception\ApiException` (a `RuntimeException`) or a subclass
per documented status code, such as `NotFoundException` or
`UnprocessableEntityException` (`Http{code}Exception` for codes without a
standard reason phrase). The exception exposes `statusCode`, `operationId`,
`responseBody` and `payload`. When the error response documents a JSON model
(by exact code, `4XX`/`5XX` range or `default`), `payload` is the hydrated
model; otherwise it is the decoded JSON or `null`.

```php
try {
    $client->getPet('42');
} catch (NotFoundException $exception) {
    $problem = $exception->payload; // e.g. Problem model
}
```

## Choosing an HTTP Adapter

- `HttpClientAdapter::SymfonyHttpClient`: default, compact code, great for Symfony ecosystems
- `HttpClientAdapter::Guzzle`: good if your stack already uses Guzzle middleware
- `HttpClientAdapter::Psr18`: framework-neutral and portable


See [HTTP Client Adapters](./http-client-adapters.md) for concrete adapter examples.

## Optional Validation Hooks

If enabled in config, generated clients can validate request and response DTOs around HTTP calls.

See [Validation Strategies](./validation.md).
