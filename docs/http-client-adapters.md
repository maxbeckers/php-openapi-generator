# HTTP Client Adapters

For `GenerationTarget::Client`, the generator emits typed API clients with one of three HTTP transport styles.

## Overview

| Adapter | Constructor Dependencies | Request Method | Good Choice For |
|---|---|---|---|
| `HttpClientAdapter::SymfonyHttpClient` | `Symfony\Contracts\HttpClient\HttpClientInterface` | `request()` | Symfony apps and lightweight clients |
| `HttpClientAdapter::Guzzle` | `GuzzleHttp\ClientInterface` | `request()` + `RequestOptions` | Existing Guzzle middleware stacks |
| `HttpClientAdapter::Psr18` | `Psr\Http\Client\ClientInterface`, request factory, stream factory | `sendRequest()` | Portable libraries and framework-neutral code |

All adapters expose the same generated API signatures (`*ApiClientInterface` + `*ApiClient`).

## Generated Files

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

## Shared Runtime Behavior

- path parameters are inserted without redundant concatenation fragments
- nullable query params are omitted from the final request
- request bodies use generated `toArray()` output
- response payloads are mapped back via generated `fromArray()` methods
- `void` operations still execute HTTP calls and skip response deserialization

## Typical Configuration

```php
use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;
use MaxBeckers\OpenApiGenerator\Config\HttpClientAdapter;

$config->generationTarget = GenerationTarget::Client;
$config->httpClient = HttpClientAdapter::SymfonyHttpClient; // symfony|guzzle|psr18
$config->httpClientVersion = '7.0'; // optional, when adapter majors require different generated code
```

Leave `$config->httpClientVersion` as `null` if you do not need adapter-version-specific generation yet.

## Adapter-Specific Guides

- [Symfony HttpClient Adapter](./http-client-adapters/symfony-http-client.md)
- [Guzzle Adapter](./http-client-adapters/guzzle.md)
- [PSR-18 Adapter](./http-client-adapters/psr18.md)
