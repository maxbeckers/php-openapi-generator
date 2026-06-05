# Guzzle Adapter

Use this adapter when your application standardizes on Guzzle.

## Config

```php
$config->generationTarget = GenerationTarget::Client;
$config->httpClient = HttpClientAdapter::Guzzle;
$config->httpClientVersion = '7.8';
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
$options = [];
$options[RequestOptions::QUERY] = $query;
$options[RequestOptions::JSON] = $body->toArray();

$response = $this->httpClient->request('PUT', $this->baseUrl . "/pets/{$petId}", $options);
```

## Response Mapping

The generated code decodes response JSON explicitly:

```php
$data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
```

Then maps the payload to generated models via `fromArray()`.

## Notes

- Best when middleware, retries, or observability already rely on Guzzle handlers.
- Uses `RequestOptions` constants for predictable option names.
