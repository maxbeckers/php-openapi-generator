# PSR-18 Adapter

Use this adapter when you need framework-neutral client interoperability.

## Config

```php
$config->generationTarget = GenerationTarget::Client;
$config->httpClient = HttpClientAdapter::Psr18;
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
$url = rtrim($this->baseUrl, '/') . '/pets/' . rawurlencode((string) $petId);
$request = $this->requestFactory->createRequest('GET', $url);
$response = $this->httpClient->sendRequest($request);
```

For JSON bodies, the generator sets content type and stream body:

```php
$bodyStream = $this->streamFactory->createStream(json_encode($body->toRequestArray(), JSON_THROW_ON_ERROR));
$request = $request->withHeader('Content-Type', 'application/json')->withBody($bodyStream);
```

## Response Mapping

The response body is decoded by the generated `decodeJsonResponse()` helper,
which rejects non-JSON content types and invalid JSON with an
`UnexpectedValueException`, and then mapped to generated models.

## Notes

- Fully PSR-compliant and portable across many HTTP client implementations.
- Recommended if your library must not hard-depend on Symfony or Guzzle contracts.
