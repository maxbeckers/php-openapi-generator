# PSR-18 Adapter

Use this adapter when you need framework-neutral client interoperability.

## Config

```php
$config->generationTarget = GenerationTarget::Client;
$config->httpClient = HttpClientAdapter::Psr18;
$config->httpClientVersion = '1.1';
```

## Constructor Signature

```php
public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly string $baseUrl,
    private readonly RequestFactoryInterface $requestFactory,
    private readonly StreamFactoryInterface $streamFactory,
) {
}
```

## Request Style

```php
$url = $this->baseUrl . "/pets/{$petId}";
$request = $this->requestFactory->createRequest('GET', $url);
$response = $this->httpClient->sendRequest($request);
```

For JSON bodies, the generator sets content type and stream body:

```php
$bodyStream = $this->streamFactory->createStream(json_encode($body->toArray(), JSON_THROW_ON_ERROR));
$request = $request->withHeader('Content-Type', 'application/json')->withBody($bodyStream);
```

## Response Mapping

Response body is decoded with `json_decode(..., JSON_THROW_ON_ERROR)` and then mapped to generated models.

## Notes

- Fully PSR-compliant and portable across many HTTP client implementations.
- Recommended if your library must not hard-depend on Symfony or Guzzle contracts.
