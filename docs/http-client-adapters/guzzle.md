# Guzzle Adapter

Use this adapter when your application standardizes on Guzzle.

## Config

```php
$config->generationTarget = GenerationTarget::Client;
$config->httpClient = HttpClientAdapter::Guzzle;
```

## Constructor Signature

```php
public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly string $baseUrl,
) {
}
```

## Request Style

```php
$url = rtrim($this->baseUrl, '/') . '/pets/' . rawurlencode((string) $petId)
    . ($queryString === '' ? '' : '?' . $queryString);
$options = [];
$options[RequestOptions::JSON] = $body->toRequestArray();
$options[RequestOptions::HTTP_ERRORS] = false;

$response = $this->httpClient->request('PUT', $url, $options);
```

## Response Mapping

The generated code decodes response JSON explicitly and rejects non-JSON
content types or invalid JSON with an `UnexpectedValueException`:

```php
$data = $this->decodeJsonResponse($response->getHeaderLine('Content-Type'), (string) $response->getBody(), 'updatePet');
```

Then maps the payload to generated models via `fromArray()`.

## Notes

- Best when middleware, retries, or observability already rely on Guzzle handlers.
- Uses `RequestOptions` constants for predictable option names.
