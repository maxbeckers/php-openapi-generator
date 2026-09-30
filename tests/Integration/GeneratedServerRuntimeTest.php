<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Http\Request as LaravelRequest;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;
use MaxBeckers\OpenApiGenerator\Config\FrameworkTarget;
use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;
use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Config\ValidationStrategy;
use MaxBeckers\OpenApiGenerator\Tests\Support\GeneratedCodeTestSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Executes generated Symfony and Laravel controller actions with real framework request objects.
 */
final class GeneratedServerRuntimeTest extends TestCase
{
    use GeneratedCodeTestSupport;

    private const FIXTURES_DIR = __DIR__ . '/../Fixtures';

    private ?\Closure $autoloader = null;
    private string $namespace;
    private FrameworkTarget $framework;

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
     * @return iterable<string, array{FrameworkTarget}>
     */
    public static function frameworks(): iterable
    {
        yield 'symfony' => [FrameworkTarget::Symfony];
        yield 'laravel' => [FrameworkTarget::Laravel];
    }

    #[DataProvider('frameworks')]
    public function testParametersAreReadAndConvertedToDeclaredTypes(FrameworkTarget $framework): void
    {
        $controller = $this->createController($framework, ['getWidget' => ['id' => 'w1', 'name' => 'Widget']]);

        $response = $this->call($controller, 'getWidget', '/widgets/{widgetId}', Request::create(
            '/widgets/a%20b?includeArchived=true&limit=5&tags=red&tags=blue&ids=1,2&labels=big%20round&codes=x%7Cy'
            . '&status=archived&since=2026-01-02&updatedAfter=2026-01-02T03%3A04%3A05%2B00%3A00',
            'GET',
            [],
            ['session' => 'abc'],
            [],
            ['HTTP_X_REQUEST_ID' => 'req-1', 'HTTP_X_TRACE' => 'trace-a,trace-b'],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['id' => 'w1', 'name' => 'Widget'], json_decode((string) $response->getContent(), true));

        $arguments = $controller->calls['getWidget'];
        self::assertSame('a b', $arguments[0]);
        self::assertTrue($arguments[1]);
        self::assertSame(5, $arguments[2]);
        self::assertSame(['red', 'blue'], $arguments[3]);
        self::assertSame([1, 2], $arguments[4]);
        self::assertSame(['big', 'round'], $arguments[5]);
        self::assertSame(['x', 'y'], $arguments[6]);
        self::assertSame($this->enum('WidgetStatus', 'archived'), $arguments[7]);
        self::assertInstanceOf(\DateTimeImmutable::class, $arguments[8]);
        self::assertSame('2026-01-02 00:00:00', $arguments[8]->format('Y-m-d H:i:s'));
        self::assertSame('2026-01-02T03:04:05+00:00', $arguments[9]->format(\DateTimeInterface::RFC3339));
        self::assertSame('req-1', $arguments[10]);
        self::assertSame(['trace-a', 'trace-b'], $arguments[11]);
        self::assertSame('abc', $arguments[12]);
    }

    #[DataProvider('frameworks')]
    public function testOptionalParametersDefaultToNullAndBracketArraysAreAccepted(FrameworkTarget $framework): void
    {
        $controller = $this->createController($framework, [
            'getWidget' => ['id' => 'w1', 'name' => 'Widget'],
            'listWidgets' => [],
        ]);

        $this->call($controller, 'getWidget', '/widgets/{widgetId}', Request::create(
            '/widgets/w1',
            'GET',
            [],
            ['session' => 's'],
            [],
            ['HTTP_X_REQUEST_ID' => 'req'],
        ));
        self::assertSame(['w1', null, null, null, null, null, null, null, null, null, 'req', null, 's'], $controller->calls['getWidget']);

        $this->call($controller, 'listWidgets', '/widgets', Request::create('/widgets?tag%5B%5D=a&tag%5B%5D=b%26c'));
        self::assertSame([['a', 'b&c']], $controller->calls['listWidgets']);
    }

    /**
     * @return iterable<string, array{FrameworkTarget, string, array<string, string>, string}>
     */
    public static function invalidRequests(): iterable
    {
        foreach (self::frameworks() as $name => [$framework]) {
            yield $name . ' invalid int' => [$framework, '/widgets/w1?limit=five', ['HTTP_X_REQUEST_ID' => 'r'], 'Invalid value for parameter "limit".'];
            yield $name . ' invalid bool' => [$framework, '/widgets/w1?includeArchived=maybe', ['HTTP_X_REQUEST_ID' => 'r'], 'Invalid value for parameter "includeArchived".'];
            yield $name . ' invalid enum' => [$framework, '/widgets/w1?status=deleted', ['HTTP_X_REQUEST_ID' => 'r'], 'Invalid value for parameter "status".'];
            yield $name . ' invalid date' => [$framework, '/widgets/w1?since=02.01.2026', ['HTTP_X_REQUEST_ID' => 'r'], 'Invalid value for parameter "since".'];
            yield $name . ' invalid list item' => [$framework, '/widgets/w1?ids=1,x', ['HTTP_X_REQUEST_ID' => 'r'], 'Invalid value for parameter "ids".'];
            yield $name . ' missing header' => [$framework, '/widgets/w1', [], 'Missing required parameter "X-Request-ID".'];
        }
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('invalidRequests')]
    public function testInvalidParametersAreRejectedAsBadRequests(
        FrameworkTarget $framework,
        string $uri,
        array $server,
        string $message,
    ): void {
        $controller = $this->createController($framework, ['getWidget' => ['id' => 'w1', 'name' => 'Widget']]);

        try {
            $this->call($controller, 'getWidget', '/widgets/{widgetId}', Request::create($uri, 'GET', [], ['session' => 's'], [], $server));
            self::fail('Expected a bad request exception.');
        } catch (BadRequestHttpException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertArrayNotHasKey('getWidget', $controller->calls);
    }

    #[DataProvider('frameworks')]
    public function testPathHeaderAndCookieDatesAndEnumsAreConverted(FrameworkTarget $framework): void
    {
        $controller = $this->createController($framework, []);

        $response = $this->call($controller, 'getReport', '/reports/{day}/{kind}', Request::create(
            '/reports/2026-03-04/active',
            'GET',
            [],
            ['asOf' => '2026-01-31'],
            [],
            ['HTTP_X_SINCE' => '2026-03-04T05:06:07+02:00'],
        ));

        self::assertSame(204, $response->getStatusCode());
        [$day, $kind, $since, $asOf] = $controller->calls['getReport'];
        self::assertSame('2026-03-04', $day->format('Y-m-d'));
        self::assertSame($this->enum('WidgetStatus', 'active'), $kind);
        self::assertSame('2026-03-04T05:06:07+02:00', $since->format(\DateTimeInterface::RFC3339));
        self::assertSame('2026-01-31', $asOf->format('Y-m-d'));
    }

    #[DataProvider('frameworks')]
    public function testResponsesUseStatusCodesMatchingTheReturnedPayload(FrameworkTarget $framework): void
    {
        $controller = $this->createController($framework, [
            'getJob' => ['JobPending', ['progress' => 40]],
            'listWidgetNames' => ['alpha', 'beta'],
            'ping' => ['Pong', ['ok' => true]],
            'upsertWidget' => null,
        ]);

        $job = $this->call($controller, 'getJob', '/jobs/{jobId}', Request::create('/jobs/j1'));
        self::assertSame(202, $job->getStatusCode());
        self::assertSame(['progress' => 40], json_decode((string) $job->getContent(), true));

        $controller->results['getJob'] = ['JobResult', ['result' => 'done']];
        $finished = $this->call($controller, 'getJob', '/jobs/{jobId}', Request::create('/jobs/j1'));
        self::assertSame(200, $finished->getStatusCode());

        $names = $this->call($controller, 'listWidgetNames', '/widget-names', Request::create('/widget-names'));
        self::assertSame(['alpha', 'beta'], json_decode((string) $names->getContent(), true));

        $pong = $this->call($controller, 'ping', '/ping', Request::create('/ping'));
        self::assertSame(200, $pong->getStatusCode());
        self::assertSame(['ok' => true], json_decode((string) $pong->getContent(), true));

        $upsert = $this->call($controller, 'upsertWidget', '/widgets/{widgetId}', Request::create(
            '/widgets/w1',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"name":"Gizmo"}',
        ));
        self::assertSame(204, $upsert->getStatusCode());
        self::assertSame('Gizmo', $controller->calls['upsertWidget'][1]->name);
    }

    #[DataProvider('frameworks')]
    public function testJsonRequestBodiesAreHydrated(FrameworkTarget $framework): void
    {
        $controller = $this->createController($framework, ['createWidget' => ['id' => 'w9', 'name' => 'Gizmo']]);

        $response = $this->call($controller, 'createWidget', '/widgets', $this->jsonRequest('/widgets', 'POST', '{"name":"Gizmo","color":"red"}'));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['id' => 'w9', 'name' => 'Gizmo'], json_decode((string) $response->getContent(), true));
        [$body] = $controller->calls['createWidget'];
        self::assertInstanceOf($this->namespace . '\\Model\\WidgetInput', $body);
        self::assertSame('Gizmo', $body->name);
        self::assertSame('red', $body->color);
    }

    #[DataProvider('frameworks')]
    public function testOptionalRequestBodiesMayBeOmitted(FrameworkTarget $framework): void
    {
        $controller = $this->createController($framework, ['touchWidget' => ['id' => 'w1', 'name' => 'Widget']]);

        $this->call($controller, 'touchWidget', '/widgets/{widgetId}', $this->jsonRequest('/widgets/w1', 'PATCH', ''));
        self::assertSame(['w1', null], $controller->calls['touchWidget']);

        $this->call($controller, 'touchWidget', '/widgets/{widgetId}', $this->jsonRequest('/widgets/w1', 'PATCH', '{"name":"Gizmo"}'));
        self::assertSame('Gizmo', $controller->calls['touchWidget'][1]->name);
    }

    /**
     * @return iterable<string, array{FrameworkTarget, string, string}>
     */
    public static function malformedBodies(): iterable
    {
        foreach (self::frameworks() as $name => [$framework]) {
            yield $name . ' missing body' => [$framework, '', 'Request body is required.'];
            yield $name . ' invalid json' => [$framework, '{"name":', 'Request body is not valid JSON.'];
            yield $name . ' scalar json' => [$framework, '"Gizmo"', 'Request body must be a JSON object or array.'];
            yield $name . ' wrong property type' => [$framework, '{"name":5}', 'Invalid request body.'];
        }
    }

    #[DataProvider('malformedBodies')]
    public function testMalformedRequestBodiesAreRejectedAsBadRequests(FrameworkTarget $framework, string $content, string $message): void
    {
        $controller = $this->createController($framework, []);

        try {
            $this->call($controller, 'createWidget', '/widgets', $this->jsonRequest('/widgets', 'POST', $content));
            self::fail('Expected a bad request exception.');
        } catch (BadRequestHttpException $exception) {
            self::assertSame($message, $exception->getMessage());
            self::assertSame(400, $exception->getStatusCode());
        }

        self::assertArrayNotHasKey('createWidget', $controller->calls);
    }

    /**
     * @return iterable<string, array{FrameworkTarget, ValidationStrategy}>
     */
    public static function requestValidationStrategies(): iterable
    {
        foreach (self::frameworks() as $name => [$framework]) {
            yield $name . ' native' => [$framework, ValidationStrategy::NativeMethod];
            yield $name . ' symfony constraints' => [$framework, ValidationStrategy::SymfonyConstraints];
        }
    }

    #[DataProvider('requestValidationStrategies')]
    public function testInvalidRequestPayloadsAreRejectedAsUnprocessable(FrameworkTarget $framework, ValidationStrategy $strategy): void
    {
        $controller = $this->createController($framework, ['createWidget' => ['id' => 'w1', 'name' => 'Gizmo']], $strategy);

        foreach (['{"name":"x"}', '{}'] as $content) {
            try {
                $this->call($controller, 'createWidget', '/widgets', $this->jsonRequest('/widgets', 'POST', $content));
                self::fail('Expected an unprocessable entity exception for ' . $content);
            } catch (UnprocessableEntityHttpException $exception) {
                self::assertSame(422, $exception->getStatusCode());
                self::assertInstanceOf(\InvalidArgumentException::class, $exception->getPrevious());
                self::assertStringContainsString('name', $exception->getMessage());
            }
        }
        self::assertArrayNotHasKey('createWidget', $controller->calls);

        $response = $this->call($controller, 'createWidget', '/widgets', $this->jsonRequest('/widgets', 'POST', '{"name":"Gizmo"}'));
        self::assertSame(201, $response->getStatusCode());
    }

    public function testLaravelValidationFailuresUseLaravelValidationExceptions(): void
    {
        $container = new Container();
        $container->instance('validator', new ValidationFactory(new Translator(new ArrayLoader(), 'en')));
        Facade::setFacadeApplication($container);

        try {
            $controller = $this->createController(FrameworkTarget::Laravel, ['createWidget' => ['id' => 'w1', 'name' => 'Gizmo']], ValidationStrategy::LaravelValidation);

            try {
                $this->call($controller, 'createWidget', '/widgets', $this->jsonRequest('/widgets', 'POST', '{"name":"x"}'));
                self::fail('Expected a Laravel validation exception.');
            } catch (ValidationException $exception) {
                self::assertSame(422, $exception->status);
                self::assertArrayHasKey('name', $exception->errors());
            }
            self::assertArrayNotHasKey('createWidget', $controller->calls);

            $response = $this->call($controller, 'createWidget', '/widgets', $this->jsonRequest('/widgets', 'POST', '{"name":"Gizmo"}'));
            self::assertSame(201, $response->getStatusCode());

            $controller->results['createWidget'] = ['id' => 'w1', 'name' => 'x'];
            $this->expectException(\InvalidArgumentException::class);
            $this->call($controller, 'createWidget', '/widgets', $this->jsonRequest('/widgets', 'POST', '{"name":"Gizmo"}'));
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
        }
    }

    #[DataProvider('requestValidationStrategies')]
    public function testInvalidResponsePayloadsAreReportedAsServerErrors(FrameworkTarget $framework, ValidationStrategy $strategy): void
    {
        $controller = $this->createController($framework, ['getWidget' => ['id' => 'w1', 'name' => 'x']], $strategy);

        try {
            $this->call($controller, 'getWidget', '/widgets/{widgetId}', Request::create('/widgets/w1', 'GET', [], ['session' => 's'], [], ['HTTP_X_REQUEST_ID' => 'r']));
            self::fail('Expected response validation to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertNotInstanceOf(HttpExceptionInterface::class, $exception);
            self::assertStringContainsString('name', $exception->getMessage());
        }
    }

    #[DataProvider('frameworks')]
    public function testDefaultOperationStubsThrowBadMethodCall(FrameworkTarget $framework): void
    {
        $controller = $this->createController($framework, [], ValidationStrategy::None, false);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Override createWidget() or createWidgetAction() to implement this operation.');

        $this->call($controller, 'createWidget', '/widgets', $this->jsonRequest('/widgets', 'POST', '{"name":"Gizmo"}'));
    }

    private function jsonRequest(string $uri, string $method, string $content): Request
    {
        return Request::create($uri, $method, [], [], [], ['CONTENT_TYPE' => 'application/json'], $content);
    }

    private function call(object $controller, string $operation, string $routePath, Request $request): Response
    {
        $parameters = $this->matchPath($routePath, $request->getPathInfo());

        if ($this->framework === FrameworkTarget::Laravel) {
            $laravelRequest = LaravelRequest::createFromBase($request);
            $route = new LaravelRoute($request->getMethod(), ltrim($routePath, '/'), []);
            $route->bind($laravelRequest);
            $laravelRequest->setRouteResolver(static fn (): LaravelRoute => $route);
            $request = $laravelRequest;
        } else {
            foreach ($parameters as $name => $value) {
                $request->attributes->set($name, $value);
            }
        }

        $response = $controller->{$operation . 'Action'}($request);
        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function matchPath(string $routePath, string $path): array
    {
        $pattern = '#^' . preg_replace('/\\\\\{(\w+)\\\\}/', '(?P<$1>[^/]+)', preg_quote($routePath, '#')) . '$#';
        self::assertSame(1, preg_match($pattern, $path, $matches), 'Request path does not match the route.');

        return array_map('rawurldecode', array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY));
    }

    private function enum(string $name, string $value): \BackedEnum
    {
        /** @var class-string<\BackedEnum> $class */
        $class = $this->namespace . '\\Model\\' . $name;

        return $class::from($value);
    }

    /**
     * Generates the server code and returns a controller subclass that records arguments and returns canned results.
     *
     * @param array<string, mixed> $results            operationId => array payload, [ModelName, payload] or scalar list
     * @param bool                 $overrideOperations false keeps the generated BadMethodCallException stubs
     */
    private function createController(
        FrameworkTarget $framework,
        array $results,
        ValidationStrategy $strategy = ValidationStrategy::None,
        bool $overrideOperations = true,
    ): object {
        $this->framework = $framework;
        $this->namespace = 'Generated\\ServerRuntime' . bin2hex(random_bytes(6));

        $config = new GeneratorConfig();
        $config->specFile = 'client-runtime.yaml';
        $config->modelNamespace = $this->namespace . '\\Model';
        $config->modelOutputDir = 'Model';
        $config->apiNamespace = $this->namespace . '\\Api';
        $config->apiOutputDir = 'Api';
        $config->generationTarget = GenerationTarget::Server;
        $config->frameworkTarget = $framework;
        $config->generateFromArray = true;
        $config->generateToArray = true;
        $config->phpVersion = '8.2';
        $config->validationStrategy = $strategy;
        $config->validateServerRequest = $strategy !== ValidationStrategy::None;
        $config->validateServerResponse = $strategy !== ValidationStrategy::None;

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

        $controllerClass = $this->namespace . '\\Api\\WidgetsApiController';
        $interface = new \ReflectionClass($this->namespace . '\\Api\\WidgetsApiInterface');
        $methods = [];
        foreach ($overrideOperations ? $interface->getMethods() : [] as $method) {
            $parameters = [];
            foreach ($method->getParameters() as $parameter) {
                $parameters[] = $this->renderType($parameter->getType()) . ' $' . $parameter->getName();
            }
            $returnType = $this->renderType($method->getReturnType());
            $name = $method->getName();
            $body = '$this->calls[' . var_export($name, true) . '] = func_get_args();';
            if ($returnType !== 'void') {
                $body .= ' return $this->result(' . var_export($name, true) . ');';
            }
            $methods[] = sprintf('public function %s(%s): %s { %s }', $name, implode(', ', $parameters), $returnType, $body);
        }

        $testClass = 'RecordingController' . bin2hex(random_bytes(4));
        eval(sprintf(
            'namespace %s\\Api; final class %s extends \\%s {
                public array $calls = [];
                public array $results = [];
                private function result(string $operation): mixed {
                    $result = $this->results[$operation] ?? null;
                    if (is_array($result) && isset($result[0], $result[1]) && is_string($result[0]) && is_array($result[1])) {
                        $class = %s . $result[0];
                        return $class::fromArray($result[1]);
                    }
                    if (is_array($result) && !array_is_list($result)) {
                        return %s::fromArray($result);
                    }
                    return $result;
                }
                %s
            }',
            $this->namespace,
            $testClass,
            $controllerClass,
            var_export($this->namespace . '\\Model\\', true),
            '\\' . $this->namespace . '\\Model\\Widget',
            implode("\n", $methods),
        ));

        $fqcn = $this->namespace . '\\Api\\' . $testClass;
        $controller = new $fqcn();
        $controller->results = $results;

        return $controller;
    }

    private function renderType(?\ReflectionType $type): string
    {
        if ($type instanceof \ReflectionUnionType) {
            return implode('|', array_map(fn (\ReflectionType $inner): string => $this->renderType($inner), $type->getTypes()));
        }

        if (!$type instanceof \ReflectionNamedType) {
            return (string) $type;
        }

        $name = $type->isBuiltin() ? $type->getName() : '\\' . $type->getName();
        if ($type->allowsNull() && $name !== 'mixed' && $name !== 'null') {
            return '?' . $name;
        }

        return $name;
    }
}
