<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Integration;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response as PsrResponse;
use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;
use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Config\HttpClientAdapter;
use MaxBeckers\OpenApiGenerator\Config\ValidationStrategy;
use MaxBeckers\OpenApiGenerator\Tests\Support\GeneratedCodeTestSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface as PsrClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Executes generated API clients against fake transports for every HTTP client adapter.
 */
final class GeneratedClientRuntimeTest extends TestCase
{
    use GeneratedCodeTestSupport;

    private const FIXTURES_DIR = __DIR__ . '/../Fixtures';
    private const BASE_URL = 'https://api.example.test/v1/';
    private const JSON = ['Content-Type' => 'application/json'];

    private ?\Closure $autoloader = null;
    private string $namespace;

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    private array $requests = [];

    /** @var list<array{int, array<string, string>, string}> */
    private array $responses = [];

    private ?MockHandler $guzzleHandler = null;

    protected function setUp(): void
    {
        $this->initializeGeneratedCodeTestSupport();
    }

    protected function tearDown(): void
    {
        if ($this->autoloader !== null) {
            spl_autoload_unregister($this->autoloader);
        }
        $this->removeGeneratedCodeOutput();
    }

    /**
     * @return iterable<string, array{HttpClientAdapter}>
     */
    public static function adapters(): iterable
    {
        foreach (HttpClientAdapter::cases() as $adapter) {
            yield $adapter->value => [$adapter];
        }
    }

    #[DataProvider('adapters')]
    public function testRequestLinePathQueryHeadersAndCookiesAreSerialized(HttpClientAdapter $adapter): void
    {
        $client = $this->createClient($adapter);
        $this->queueResponse(200, self::JSON, '{"id":"w1","name":"Widget"}');
        $this->queueResponse(200, self::JSON, '{"id":"w2","name":"Widget"}');

        $widget = $client->getWidget(
            'a b/ü',
            true,
            5,
            ['red', 'blue'],
            [1, 2],
            ['big', 'round'],
            ['x', 'y'],
            $this->enum('WidgetStatus', 'archived'),
            new \DateTimeImmutable('2026-01-02 12:30:00'),
            new \DateTimeImmutable('2026-01-02T03:04:05+00:00'),
            'req-1',
            ['trace-a', 'trace-b'],
            'abc ;=',
        );

        self::assertInstanceOf($this->model('Widget'), $widget);
        self::assertSame('w1', $widget->id);

        $request = $this->requests[0];
        self::assertSame('GET', $request['method']);
        self::assertSame(
            'https://api.example.test/v1/widgets/a%20b%2F%C3%BC'
            . '?includeArchived=true&limit=5&tags=red&tags=blue&ids=1,2&labels=big%20round&codes=x%7Cy'
            . '&status=archived&since=2026-01-02&updatedAfter=2026-01-02T03%3A04%3A05%2B00%3A00',
            $request['url'],
        );
        self::assertSame('req-1', $request['headers']['x-request-id'] ?? null);
        self::assertSame('trace-a,trace-b', $request['headers']['x-trace'] ?? null);
        self::assertSame('session=abc%20%3B%3D', $request['headers']['cookie'] ?? null);

        $client->getWidget('w2', false, null, null, null, null, null, null, null, null, 'req-2', null, 's2');

        $request = $this->requests[1];
        self::assertSame('https://api.example.test/v1/widgets/w2?includeArchived=false', $request['url']);
        self::assertArrayNotHasKey('x-trace', $request['headers']);
        self::assertSame('req-2', $request['headers']['x-request-id'] ?? null);
        self::assertSame('session=s2', $request['headers']['cookie'] ?? null);
    }

    #[DataProvider('adapters')]
    public function testJsonRequestBodyIsSentWithJsonContentType(HttpClientAdapter $adapter): void
    {
        $client = $this->createClient($adapter);
        $this->queueResponse(201, self::JSON, '{"id":"w1","name":"Gizmo"}');

        $input = ($this->model('WidgetInput'))::fromArray(['name' => 'Gizmo', 'color' => 'red']);
        $created = $client->createWidget($input);

        self::assertSame('Gizmo', $created->name);
        $request = $this->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://api.example.test/v1/widgets', $request['url']);
        self::assertStringStartsWith('application/json', $request['headers']['content-type'] ?? '');
        self::assertSame(['name' => 'Gizmo', 'color' => 'red'], json_decode($request['body'], true));
    }

    #[DataProvider('adapters')]
    public function testSuccessResponsesAreHydratedByStatusCode(HttpClientAdapter $adapter): void
    {
        $client = $this->createClient($adapter);
        $widgetClass = $this->model('Widget');

        $this->queueResponse(200, self::JSON, '[{"id":"w1","name":"One"},{"id":"w2","name":"Two"}]');
        $widgets = $client->listWidgets(['a&b', 'c']);
        self::assertSame('https://api.example.test/v1/widgets?tag=a%26b&tag=c', $this->requests[0]['url']);
        self::assertCount(2, $widgets);
        self::assertContainsOnlyInstancesOf($widgetClass, $widgets);
        self::assertSame('Two', $widgets[1]->name);

        $this->queueResponse(200, self::JSON, '["alpha","beta"]');
        self::assertSame(['alpha', 'beta'], $client->listWidgetNames());

        $body = ($this->model('WidgetInput'))::fromArray(['name' => 'Gizmo']);
        $this->queueResponse(200, self::JSON, '{"id":"w1","name":"Updated"}');
        self::assertSame('Updated', $client->upsertWidget('w1', $body)?->name);
        $this->queueResponse(201, self::JSON, '{"id":"w1","name":"Created"}');
        self::assertSame('Created', $client->upsertWidget('w1', $body)?->name);
        $this->queueResponse(204, [], '');
        self::assertNull($client->upsertWidget('w1', $body));
        self::assertSame('PUT', $this->requests[4]['method']);

        $this->queueResponse(204, [], '');
        $client->deleteWidget('w1');
        self::assertSame('DELETE', $this->requests[5]['method']);
        self::assertSame('https://api.example.test/v1/widgets/w1', $this->requests[5]['url']);

        $this->queueResponse(200, self::JSON, '{"result":"done"}');
        $finished = $client->getJob('j1');
        self::assertInstanceOf($this->model('JobResult'), $finished);
        self::assertSame('done', $finished->result);

        $this->queueResponse(202, self::JSON, '{"progress":40}');
        $pending = $client->getJob('j1');
        self::assertInstanceOf($this->model('JobPending'), $pending);
        self::assertSame(40, $pending->progress);

        $this->queueResponse(200, ['Content-Type' => 'application/problem+json'], '{"ok":true}');
        $pong = $client->ping();
        self::assertInstanceOf($this->model('Pong'), $pong);
        self::assertTrue($pong->ok);
    }

    /**
     * @return iterable<string, array{HttpClientAdapter, int}>
     */
    public static function errorStatuses(): iterable
    {
        foreach (HttpClientAdapter::cases() as $adapter) {
            foreach ([404, 422, 500, 302] as $status) {
                yield $adapter->value . ' ' . $status => [$adapter, $status];
            }
        }
    }

    #[DataProvider('errorStatuses')]
    public function testErrorStatusesThrowInsteadOfHydratingSuccessPayloads(HttpClientAdapter $adapter, int $status): void
    {
        $client = $this->createClient($adapter);
        $this->queueResponse($status, self::JSON, '{"title":"Nope"}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(sprintf('API operation "getWidget" failed with HTTP status %d.', $status));

        $client->getWidget('w1', null, null, null, null, null, null, null, null, null, 'req', null, 's');
    }

    #[DataProvider('adapters')]
    public function testErrorStatusesThrowForVoidOperations(HttpClientAdapter $adapter): void
    {
        $client = $this->createClient($adapter);
        $this->queueResponse(500, [], '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API operation "deleteWidget" failed with HTTP status 500.');

        $client->deleteWidget('w1');
    }

    /**
     * @return iterable<string, array{HttpClientAdapter, string, array<string, string>, string, string}>
     */
    public static function malformedResponses(): iterable
    {
        foreach (HttpClientAdapter::cases() as $adapter) {
            yield $adapter->value . ' invalid json' => [
                $adapter,
                'getWidget',
                self::JSON,
                '{"id":',
                'Invalid JSON response for operation "getWidget"',
            ];
            yield $adapter->value . ' wrong content type' => [
                $adapter,
                'getWidget',
                ['Content-Type' => 'text/html; charset=UTF-8'],
                '<html></html>',
                'Expected a JSON response for operation "getWidget", got content type "text/html; charset=UTF-8".',
            ];
            yield $adapter->value . ' scalar instead of object' => [
                $adapter,
                'getWidget',
                self::JSON,
                '"text"',
                'Expected a JSON object response for getWidget.',
            ];
            yield $adapter->value . ' object instead of list' => [
                $adapter,
                'listWidgets',
                self::JSON,
                '{"id":"w1","name":"One"}',
                'Expected a JSON array response for listWidgets.',
            ];
            yield $adapter->value . ' wrong list item type' => [
                $adapter,
                'listWidgetNames',
                self::JSON,
                '["ok", 5]',
                'Expected each listWidgetNames response item to be of type string.',
            ];
        }
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('malformedResponses')]
    public function testMalformedResponsesProduceClearErrors(
        HttpClientAdapter $adapter,
        string $operation,
        array $headers,
        string $body,
        string $expectedMessage,
    ): void {
        $client = $this->createClient($adapter);
        $this->queueResponse(200, $headers, $body);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($expectedMessage);

        match ($operation) {
            'getWidget' => $client->getWidget('w1', null, null, null, null, null, null, null, null, null, 'req', null, 's'),
            'listWidgets' => $client->listWidgets(null),
            'listWidgetNames' => $client->listWidgetNames(),
        };
    }

    /**
     * @return iterable<string, array{HttpClientAdapter, ValidationStrategy}>
     */
    public static function validatingAdapters(): iterable
    {
        foreach (HttpClientAdapter::cases() as $adapter) {
            foreach ([ValidationStrategy::NativeMethod, ValidationStrategy::SymfonyConstraints] as $strategy) {
                yield $adapter->value . ' ' . $strategy->value => [$adapter, $strategy];
            }
        }
    }

    #[DataProvider('validatingAdapters')]
    public function testClientRequestValidationRejectsInvalidBodiesBeforeSending(
        HttpClientAdapter $adapter,
        ValidationStrategy $strategy,
    ): void {
        $client = $this->createClient($adapter, $strategy);
        $invalid = ($this->model('WidgetInput'))::fromArray(['name' => 'x']);

        try {
            $client->createWidget($invalid);
            self::fail('Expected request validation to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('name', $exception->getMessage());
        }

        self::assertSame([], $this->requests, 'Invalid requests must not reach the transport.');
    }

    #[DataProvider('validatingAdapters')]
    public function testClientResponseValidationRejectsInvalidPayloads(
        HttpClientAdapter $adapter,
        ValidationStrategy $strategy,
    ): void {
        $client = $this->createClient($adapter, $strategy);
        $this->queueResponse(200, self::JSON, '[{"id":"w1","name":"Valid"}]');
        self::assertCount(1, $client->listWidgets(null));

        $this->queueResponse(200, self::JSON, '[{"id":"w1","name":"x"}]');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/name/');

        $client->listWidgets(null);
    }

    #[DataProvider('adapters')]
    public function testSecuritySchemesAddCredentialsToRequests(HttpClientAdapter $adapter): void
    {
        $client = $this->createClient($adapter, credentials: [
            'bearerAuthToken' => 'jwt-token',
            'basicAuthUsername' => 'alice',
            'basicAuthPassword' => 's3cret:pw',
            'queryKey' => 'q key&',
            'cookieKey' => 'c;v',
        ], api: 'Secure');
        for ($i = 0; $i < 7; ++$i) {
            $this->queueResponse(204, [], '');
        }

        $client->getWithBasic();
        $client->getWithHeaderKey();
        $client->getWithQueryKey('x');
        $client->getWithCookieKey();
        $client->getWithAlternatives();
        $client->getWithOptionalAuth();
        $client->getPublic();

        [$basic, $headerKey, $queryKey, $cookieKey, $alternatives, $optional, $public] = $this->requests;
        self::assertSame('Basic ' . base64_encode('alice:s3cret:pw'), $basic['headers']['authorization'] ?? null);
        self::assertArrayNotHasKey('x-api-key', $headerKey['headers'], 'Schemes without credentials are skipped.');
        self::assertArrayNotHasKey('authorization', $headerKey['headers']);
        self::assertSame('https://api.example.test/v1/secure/query-key?q=x&api_key=q%20key%26', $queryKey['url']);
        self::assertSame('auth_token=c%3Bv', $cookieKey['headers']['cookie'] ?? null);
        self::assertArrayNotHasKey('authorization', $alternatives['headers'], 'Incomplete alternatives must not be sent.');
        self::assertSame('Bearer jwt-token', $optional['headers']['authorization'] ?? null);
        self::assertArrayNotHasKey('authorization', $public['headers']);
    }

    #[DataProvider('adapters')]
    public function testSecuritySchemeGenerationCanBeDisabled(HttpClientAdapter $adapter): void
    {
        $client = $this->createClient($adapter, api: 'Secure', generateSecuritySchemes: false);
        $this->queueResponse(204, [], '');

        $client->getWithBasic();

        self::assertArrayNotHasKey('authorization', $this->requests[0]['headers']);
        self::assertFileDoesNotExist(
            $this->generatedCodeOutputDir . DIRECTORY_SEPARATOR . 'Api' . DIRECTORY_SEPARATOR . 'ApiCredentials.php',
        );
    }

    #[DataProvider('adapters')]
    public function testSecurityAlternativesAndGlobalRequirements(HttpClientAdapter $adapter): void
    {
        $client = $this->createClient($adapter, credentials: [
            'headerKey' => 'key-1',
            'basicAuthUsername' => 'bob',
            'basicAuthPassword' => 'pw',
        ], api: 'Secure');
        $this->queueResponse(204, [], '');
        $this->queueResponse(204, [], '');
        $client->getWithAlternatives();
        $client->getWithOptionalAuth();

        self::assertSame('key-1', $this->requests[0]['headers']['x-api-key'] ?? null);
        self::assertSame('Basic ' . base64_encode('bob:pw'), $this->requests[0]['headers']['authorization'] ?? null);
        self::assertArrayNotHasKey('authorization', $this->requests[1]['headers'], 'The anonymous alternative applies.');

        $this->requests = [];
        $oauthClient = $this->instantiateClient($adapter, ['oauthToken' => 'oauth-token'], 'Secure');
        $this->queueResponse(204, [], '');
        $oauthClient->getWithAlternatives();
        self::assertSame('Bearer oauth-token', $this->requests[0]['headers']['authorization'] ?? null);

        $this->requests = [];
        $widgets = $this->instantiateClient($adapter, ['bearerAuthToken' => 'global']);
        $this->queueResponse(204, [], '');
        $this->queueResponse(200, self::JSON, '{"ok":true}');
        $widgets->deleteWidget('w1');
        $widgets->ping();
        self::assertSame('Bearer global', $this->requests[0]['headers']['authorization'] ?? null, 'Global security is inherited.');
        self::assertArrayNotHasKey('authorization', $this->requests[1]['headers'], 'security: [] disables authentication.');
    }

    #[DataProvider('adapters')]
    public function testDateParametersAreFormattedPerLocation(HttpClientAdapter $adapter): void
    {
        $client = $this->createClient($adapter);
        $this->queueResponse(204, [], '');
        $this->queueResponse(204, [], '');

        $client->getReport(
            new \DateTimeImmutable('2026-03-04 23:59:59'),
            $this->enum('WidgetStatus', 'active'),
            new \DateTimeImmutable('2026-03-04T05:06:07+02:00'),
            new \DateTimeImmutable('2026-01-31 10:00:00'),
        );
        $client->getReport(new \DateTimeImmutable('2026-03-05'), $this->enum('WidgetStatus', 'archived'), null, null);

        self::assertSame('https://api.example.test/v1/reports/2026-03-04/active', $this->requests[0]['url']);
        self::assertSame('2026-03-04T05:06:07+02:00', $this->requests[0]['headers']['x-since'] ?? null);
        self::assertSame('asOf=2026-01-31', $this->requests[0]['headers']['cookie'] ?? null);
        self::assertSame('https://api.example.test/v1/reports/2026-03-05/archived', $this->requests[1]['url']);
        self::assertArrayNotHasKey('x-since', $this->requests[1]['headers']);
        self::assertArrayNotHasKey('cookie', $this->requests[1]['headers']);
    }

    /**
     * @return iterable<string, array{HttpClientAdapter, int, string, string, string, bool}>
     */
    public static function typedErrorStatuses(): iterable
    {
        foreach (HttpClientAdapter::cases() as $adapter) {
            yield $adapter->value . ' documented model' => [$adapter, 404, 'getWidget', 'NotFoundException', '{"title":"Missing"}', true];
            yield $adapter->value . ' without model' => [$adapter, 500, 'getWidget', 'InternalServerErrorException', '{"title":"Boom"}', false];
            yield $adapter->value . ' undocumented status' => [$adapter, 418, 'getWidget', 'ApiException', '{"title":"Teapot"}', false];
            yield $adapter->value . ' range model' => [$adapter, 409, 'getReport', 'ApiException', '{"title":"Conflict"}', true];
            yield $adapter->value . ' default model' => [$adapter, 503, 'getReport', 'ApiException', '{"title":"Later"}', true];
            yield $adapter->value . ' void status class' => [$adapter, 422, 'createWidget', 'UnprocessableEntityException', '', false];
        }
    }

    #[DataProvider('typedErrorStatuses')]
    public function testTypedErrorResponsesThrowStatusSpecificExceptions(
        HttpClientAdapter $adapter,
        int $status,
        string $operation,
        string $exceptionClass,
        string $body,
        bool $expectsModel,
    ): void {
        $client = $this->createClient($adapter, typedErrors: true);
        $this->queueResponse($status, $body === '' ? [] : self::JSON, $body);

        try {
            match ($operation) {
                'getWidget' => $client->getWidget('w1', null, null, null, null, null, null, null, null, null, 'req', null, 's'),
                'getReport' => $client->getReport(new \DateTimeImmutable('2026-01-01'), $this->enum('WidgetStatus', 'active'), null, null),
                'createWidget' => $client->createWidget(($this->model('WidgetInput'))::fromArray(['name' => 'Gizmo'])),
            };
            self::fail('Expected an API exception.');
        } catch (\RuntimeException $exception) {
            $baseClass = $this->namespace . '\\Api\\Exception\\ApiException';
            self::assertInstanceOf($baseClass, $exception);
            self::assertSame($this->namespace . '\\Api\\Exception\\' . $exceptionClass, $exception::class);
            self::assertSame(sprintf('API operation "%s" failed with HTTP status %d.', $operation, $status), $exception->getMessage());
            self::assertSame($status, $exception->statusCode);
            self::assertSame($status, $exception->getCode());
            self::assertSame($operation, $exception->operationId);
            self::assertSame($body, $exception->responseBody);
            if ($expectsModel) {
                self::assertInstanceOf($this->model('Problem'), $exception->payload);
                self::assertSame(json_decode($body, true)['title'], $exception->payload->title);
            } elseif ($body === '') {
                self::assertNull($exception->payload);
            } else {
                self::assertSame(json_decode($body, true), $exception->payload);
            }
        }
    }

    #[DataProvider('adapters')]
    public function testTypedErrorPayloadFallsBackForNonJsonBodies(HttpClientAdapter $adapter): void
    {
        $client = $this->createClient($adapter, typedErrors: true);
        $this->queueResponse(404, ['Content-Type' => 'text/plain'], 'not found');

        try {
            $client->getWidget('w1', null, null, null, null, null, null, null, null, null, 'req', null, 's');
            self::fail('Expected an API exception.');
        } catch (\RuntimeException $exception) {
            self::assertSame($this->namespace . '\\Api\\Exception\\NotFoundException', $exception::class);
            self::assertNull($exception->payload);
            self::assertSame('not found', $exception->responseBody);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function queueResponse(int $status, array $headers, string $body): void
    {
        if ($this->guzzleHandler !== null) {
            $this->guzzleHandler->append(new PsrResponse($status, $headers, $body));

            return;
        }

        $this->responses[] = [$status, $headers, $body];
    }

    /**
     * @return array{int, array<string, string>, string}
     */
    private function nextResponse(): array
    {
        $response = array_shift($this->responses);
        self::assertNotNull($response, 'No fake response queued.');

        return $response;
    }

    /**
     * @param array<string, string>|null $credentials named ApiCredentials constructor arguments
     */
    private function createClient(
        HttpClientAdapter $adapter,
        ValidationStrategy $strategy = ValidationStrategy::None,
        bool $typedErrors = false,
        ?array $credentials = null,
        string $api = 'Widgets',
        bool $generateSecuritySchemes = true,
    ): object {
        $this->generateClient($adapter, $strategy, $typedErrors, $generateSecuritySchemes);

        return $this->instantiateClient($adapter, $credentials, $api);
    }

    /**
     * @param array<string, string>|null $credentials named ApiCredentials constructor arguments
     */
    private function instantiateClient(HttpClientAdapter $adapter, ?array $credentials = null, string $api = 'Widgets'): object
    {
        $clientClass = $this->namespace . '\\Api\\' . $api . 'ApiClient';
        $extra = [];
        if ($credentials !== null) {
            $credentialsClass = $this->namespace . '\\Api\\ApiCredentials';
            $extra['credentials'] = new $credentialsClass(...$credentials);
        }

        return match ($adapter) {
            HttpClientAdapter::SymfonyHttpClient => new $clientClass($this->symfonyClient(), self::BASE_URL, ...$extra),
            HttpClientAdapter::Guzzle => new $clientClass($this->guzzleClient(), self::BASE_URL, ...$extra),
            HttpClientAdapter::Psr18 => $api === 'Widgets'
                ? new $clientClass($this->psr18Client(), self::BASE_URL, new HttpFactory(), new HttpFactory(), ...$extra)
                : new $clientClass($this->psr18Client(), self::BASE_URL, new HttpFactory(), ...$extra),
        };
    }

    private function symfonyClient(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $headers = [];
            foreach ($options['normalized_headers'] ?? [] as $name => $lines) {
                $headers[strtolower((string) $name)] = implode(', ', array_map(
                    static fn (string $line): string => substr($line, strlen((string) $name) + 2),
                    $lines,
                ));
            }
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
                'body' => is_string($options['body'] ?? null) ? $options['body'] : '',
            ];

            [$status, $responseHeaders, $body] = $this->nextResponse();
            $headerLines = [];
            foreach ($responseHeaders as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }

            return new MockResponse($body, ['http_code' => $status, 'response_headers' => $headerLines]);
        });
    }

    private function guzzleClient(): GuzzleClient
    {
        $this->guzzleHandler = new MockHandler();
        $stack = HandlerStack::create($this->guzzleHandler);
        $stack->push(Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
            $this->recordPsrRequest($request);

            return $request;
        }));

        return new GuzzleClient(['handler' => $stack]);
    }

    private function psr18Client(): PsrClientInterface
    {
        $test = $this;

        return new class ($test) implements PsrClientInterface {
            public function __construct(private readonly GeneratedClientRuntimeTest $test)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->test->handlePsrRequest($request);
            }
        };
    }

    /**
     * @internal used by the PSR-18 fake client
     */
    public function handlePsrRequest(RequestInterface $request): ResponseInterface
    {
        $this->recordPsrRequest($request);

        return $this->nextPsrResponse();
    }

    private function recordPsrRequest(RequestInterface $request): void
    {
        $headers = [];
        foreach (array_keys($request->getHeaders()) as $name) {
            $headers[strtolower((string) $name)] = $request->getHeaderLine((string) $name);
        }

        $this->requests[] = [
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'headers' => $headers,
            'body' => (string) $request->getBody(),
        ];
    }

    private function nextPsrResponse(): ResponseInterface
    {
        [$status, $headers, $body] = $this->nextResponse();

        return new PsrResponse($status, $headers, $body);
    }

    private function model(string $name): string
    {
        return $this->namespace . '\\Model\\' . $name;
    }

    private function enum(string $name, string $value): \BackedEnum
    {
        /** @var class-string<\BackedEnum> $class */
        $class = $this->model($name);

        return $class::from($value);
    }

    private function generateClient(
        HttpClientAdapter $adapter,
        ValidationStrategy $strategy,
        bool $typedErrors = false,
        bool $generateSecuritySchemes = true,
    ): void {
        $this->namespace = 'Generated\\ClientRuntime' . bin2hex(random_bytes(6));
        $validate = $strategy !== ValidationStrategy::None;

        $config = new GeneratorConfig();
        $config->specFile = 'client-runtime.yaml';
        $config->modelNamespace = $this->namespace . '\\Model';
        $config->modelOutputDir = 'Model';
        $config->apiNamespace = $this->namespace . '\\Api';
        $config->apiOutputDir = 'Api';
        $config->generationTarget = GenerationTarget::Client;
        $config->httpClient = $adapter;
        $config->validationStrategy = $strategy;
        $config->validateClientRequest = $validate;
        $config->validateClientResponse = $validate;
        $config->generateFromArray = true;
        $config->generateToArray = true;
        $config->phpVersion = '8.2';
        $config->typedErrorResponses = $typedErrors;
        $config->generateSecuritySchemes = $generateSecuritySchemes;

        $outputDir = $this->generateCode($config, self::FIXTURES_DIR, false);

        $prefix = $this->namespace . '\\';
        $this->autoloader = static function (string $class) use ($prefix, $outputDir): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $path = $outputDir . DIRECTORY_SEPARATOR
                . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        };
        spl_autoload_register($this->autoloader);
    }
}
