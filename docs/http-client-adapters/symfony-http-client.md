# Symfony HttpClient Adapter

Use this adapter when generating clients for projects that already use Symfony contracts.

## Config

```php
$config->generationTarget = GenerationTarget::Client;
$config->httpClient = HttpClientAdapter::SymfonyHttpClient;
$config->httpClientVersion = '7.0';
```

## Constructor Signature

```php
public function __construct(
    private readonly HttpClientInterface $httpClient,
    private readonly string $baseUrl,
) {
}
```

## Request Style

```php
$response = $this->httpClient->request(
    'GET',
    $this->baseUrl . "/pets/{$petId}",
);
```

For operations with a request body, the generator sends:

```php
[
    'json' => $body->toArray(),
]
```

For operations with query parameters, the generator sends:

```php
[
    'query' => $query,
]
```

## Response Mapping

- Single object responses: `Pet::fromArray($response->toArray())`
- Array responses: `array_map(static fn(array $item) => Pet::fromArray($item), $response->toArray())`
- Void responses: no body mapping

## Notes

- Works well with Symfony apps and standalone projects that install `symfony/http-client`.
- Keeps generated code compact because request and JSON decoding are handled by Symfony's client API.
