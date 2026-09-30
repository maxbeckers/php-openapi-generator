# Symfony HttpClient Adapter

Use this adapter when generating clients for projects that already use Symfony contracts.

## Config

```php
$config->generationTarget = GenerationTarget::Client;
$config->httpClient = HttpClientAdapter::SymfonyHttpClient;
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
$url = rtrim($this->baseUrl, '/') . '/pets/' . rawurlencode((string) $petId);
$response = $this->httpClient->request(
    'GET',
    $url,
);
```

For operations with a request body, the generator sends:

```php
[
    'json' => $body->toRequestArray(),
]
```

Query parameters are serialized into the URL by the generated
`buildQueryString()` helper according to their OpenAPI `style`/`explode`
settings.

Header parameters are sent as request headers. Cookie parameters are encoded
into the `Cookie` header. Any non-2xx response throws a `RuntimeException`
containing the operation ID and HTTP status code.

## Response Mapping

- The body is read with `$response->getContent(false)` and decoded by the
  generated `decodeJsonResponse()` helper, which rejects non-JSON content types
  and invalid JSON with an `UnexpectedValueException`.
- Single object responses: `Pet::fromResponseArray($data)`
- Array responses: each item is mapped with `Pet::fromResponseArray($item)`
- Void responses: no body mapping

## Notes

- Works well with Symfony apps and standalone projects that install `symfony/http-client`.
- Keeps generated code compact because request and JSON decoding are handled by Symfony's client API.
