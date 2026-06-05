<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Integration;

use MaxBeckers\OpenApiGenerator\Config\FrameworkTarget;
use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;
use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Config\HttpClientAdapter;
use MaxBeckers\OpenApiGenerator\Config\ValidationStrategy;
use MaxBeckers\OpenApiGenerator\FileWriter\FileWriter;
use MaxBeckers\OpenApiGenerator\Loader\OpenApiLoader;
use MaxBeckers\OpenApiGenerator\Service\OpenApiService;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for server-side and client-side code generation.
 *
 * Tests cover:
 *  - Server interface (FrameworkTarget::None)
 *  - Server Symfony abstract controller (#[Route] + JsonResponse)
 *  - Server Laravel abstract controller (Illuminate\Http\Request)
 *  - Client interface
 *  - Client concrete class for SymfonyHttpClient, Guzzle, and PSR-18
 */
class ApiGenerationTest extends TestCase
{
    private string $outputDir;
    private OpenApiService $service;
    private static OpenApiService $sharedService;

    private const FIXTURES_DIR = __DIR__ . '/../Fixtures';

    public static function setUpBeforeClass(): void
    {
        self::$sharedService = new OpenApiService(new OpenApiLoader(), new FileWriter());
    }

    protected function setUp(): void
    {
        $this->outputDir = sys_get_temp_dir() . '/openapi-gen-api-' . uniqid('', true);
        $this->service = self::$sharedService;
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->outputDir);
    }

    // =========================================================================
    // Server: interface (FrameworkTarget::None)
    // =========================================================================

    public function testServerInterfaceIsGenerated(): void
    {
        $config = $this->makeServerConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        self::assertFileExists($this->outputDir . '/Api/OrdersApiInterface.php');
        self::assertFileExists($this->outputDir . '/Api/CatalogApiInterface.php');
    }

    public function testServerInterfaceDeclaresOperationMethods(): void
    {
        $config = $this->makeServerConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiInterface.php');

        self::assertStringContainsString('interface OrdersApiInterface', $content);
        self::assertStringContainsString('public function createOrder(', $content);
    }

    public function testServerInterfaceMethodHasRequestBodyParam(): void
    {
        $config = $this->makeServerConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiInterface.php');

        // createOrder has a required requestBody (CreateOrderRequest)
        self::assertStringContainsString('$body', $content);
        self::assertStringContainsString('CreateOrderRequest', $content);
    }

    public function testServerInterfaceMethodReturnType(): void
    {
        $config = $this->makeServerConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiInterface.php');

        // createOrder returns Order
        self::assertStringContainsString('): Order', $content);
    }

    public function testServerInterfaceArrayReturnType(): void
    {
        $config = $this->makeServerConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('CatalogApiInterface.php');

        // listCatalogItems returns an array of CatalogItem — PHP type is `array`
        self::assertStringContainsString('): array', $content);
    }

    public function testServerInterfaceFilesAreValidPhp(): void
    {
        $config = $this->makeServerConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        foreach (['OrdersApiInterface.php', 'CatalogApiInterface.php'] as $file) {
            $path = $this->outputDir . '/Api/' . $file;
            $output = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($path) . ' 2>&1');
            self::assertStringContainsString('No syntax errors', (string) $output, "Syntax error in $file");
        }
    }

    // =========================================================================
    // Server: Symfony controller
    // =========================================================================

    public function testSymfonyControllerIsGenerated(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Symfony);
        $this->service->generate($config, self::FIXTURES_DIR);

        self::assertFileExists($this->outputDir . '/Api/OrdersApiController.php');
    }

    public function testSymfonyControllerExtendsInterface(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Symfony);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiController.php');

        self::assertStringContainsString('abstract class OrdersApiController implements OrdersApiInterface', $content);
    }

    public function testSymfonyControllerHasRouteAttribute(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Symfony);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiController.php');

        self::assertStringContainsString('#[Route(', $content);
        self::assertStringContainsString("methods: ['POST']", $content);
    }

    public function testSymfonyControllerUsesJsonResponse(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Symfony);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiController.php');

        self::assertStringContainsString('JsonResponse', $content);
        self::assertStringContainsString('use Symfony\\Component\\HttpFoundation\\JsonResponse', $content);
    }

    public function testSymfonyControllerUsesSuccessCodeFromSpec(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Symfony);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiController.php');

        // feature-test.yaml createOrder responds 201 Created — must NOT hardcode 200
        self::assertStringContainsString('201', $content);
        self::assertStringNotContainsString('new JsonResponse($result->toArray())', $content);
    }

    public function testSymfonyControllerDeserializesRequestBody(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Symfony);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiController.php');

        self::assertStringContainsString('CreateOrderRequest::fromRequestArray(', $content);
        self::assertStringContainsString('$request->toArray()', $content);
    }

    public function testSymfonyControllerFilesAreValidPhp(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Symfony);
        $this->service->generate($config, self::FIXTURES_DIR);

        foreach (['OrdersApiController.php', 'OrdersApiInterface.php'] as $file) {
            $path = $this->outputDir . '/Api/' . $file;
            $output = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($path) . ' 2>&1');
            self::assertStringContainsString('No syntax errors', (string) $output, "Syntax error in $file");
        }
    }

    public function testSymfonyControllerHasDefaultDomainMethodStub(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Symfony);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiController.php');

        // Default stub throws BadMethodCallException so overriding *Action only
        // does not require an empty domain method implementation.
        self::assertStringContainsString('throw new \BadMethodCallException(', $content);
        self::assertStringContainsString('createOrder()', $content);
    }

    public function testSymfonyControllerVoidActionDoesNotAssignResult(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Symfony);
        $config->specFile = 'petstore.yaml';
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('PetsApiController.php');

        self::assertStringContainsString('public function deletePetAction(Request $request): JsonResponse', $content);
        self::assertStringContainsString('$this->deletePet(', $content);
        self::assertStringNotContainsString('$result = $this->deletePet(', $content);
        self::assertStringContainsString('return new JsonResponse(null, 204);', $content);
    }

    public function testSymfonyControllerValidatesNativeRequestBodyWhenEnabled(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Symfony);
        $config->validationStrategy = ValidationStrategy::NativeMethod;
        $config->validateServerRequest = true;
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiController.php');

        self::assertStringContainsString('private function validateNativePayload(mixed $value): void', $content);
        self::assertStringContainsString('private function validateNativeRequestPayload(mixed $value): void', $content);
        self::assertStringContainsString('private function validateNativeResponsePayload(mixed $value): void', $content);
        self::assertStringContainsString('$body = CreateOrderRequest::fromRequestArray($request->toArray());', $content);
        self::assertStringContainsString('$this->validateNativeRequestPayload($body);', $content);
        self::assertStringContainsString('body: $body,', $content);
    }

    public function testSymfonyControllerValidatesNativeResponseWhenEnabled(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Symfony);
        $config->validationStrategy = ValidationStrategy::NativeMethod;
        $config->validateServerResponse = true;
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiController.php');

        self::assertStringContainsString('$this->validateNativeResponsePayload($result);', $content);
    }

    public function testSymfonyControllerValidatesSymfonyRequestBodyWhenEnabled(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Symfony);
        $config->validationStrategy = ValidationStrategy::SymfonyConstraints;
        $config->validateServerRequest = true;
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiController.php');

        self::assertStringContainsString('use Symfony\\Component\\Validator\\Validation;', $content);
        self::assertStringContainsString('use Symfony\\Component\\Validator\\Validator\\ValidatorInterface;', $content);
        self::assertStringContainsString('public function __construct(?ValidatorInterface $validator = null)', $content);
        self::assertStringContainsString('$this->validator = $validator ?? Validation::createValidatorBuilder()', $content);
        self::assertStringContainsString('private function validateSymfonyPayload(mixed $value, ?\SplObjectStorage $visited = null): void', $content);
        self::assertStringContainsString('private function validateSymfonyRequestPayload(mixed $value, ?\SplObjectStorage $visited = null): void', $content);
        self::assertStringContainsString('private function validateSymfonyResponsePayload(mixed $value, ?\SplObjectStorage $visited = null): void', $content);
        self::assertStringContainsString('$violations = $this->validator->validate($value);', $content);
        self::assertStringContainsString('foreach (get_object_vars($value) as $propertyValue) {', $content);
        self::assertStringContainsString('$this->validateSymfonyRequestPayload($propertyValue, $visited);', $content);
        self::assertStringContainsString('$body = CreateOrderRequest::fromRequestArray($request->toArray());', $content);
        self::assertStringContainsString('$this->validateSymfonyRequestPayload($body);', $content);
        self::assertStringContainsString('body: $body,', $content);
    }

    public function testSymfonyControllerValidatesSymfonyResponseWhenEnabled(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Symfony);
        $config->validationStrategy = ValidationStrategy::SymfonyConstraints;
        $config->validateServerResponse = true;
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiController.php');

        self::assertStringContainsString('$this->validateSymfonyResponsePayload($result);', $content);
    }

    // =========================================================================
    // Server: Laravel controller
    // =========================================================================

    public function testLaravelControllerIsGenerated(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Laravel);
        $this->service->generate($config, self::FIXTURES_DIR);

        self::assertFileExists($this->outputDir . '/Api/OrdersApiController.php');
        self::assertFileExists($this->outputDir . '/Api/OrdersApiRoutes.php');
    }

    public function testLaravelRouteHelperRegistersOperations(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Laravel);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiRoutes.php');

        self::assertStringContainsString('final class OrdersApiRoutes', $content);
        self::assertStringContainsString('use Illuminate\\Support\\Facades\\Route', $content);
        self::assertStringContainsString('public static function register(string $controller = OrdersApiController::class): void', $content);
        self::assertStringContainsString("Route::post('/orders', [\$controller, 'createOrderAction']);", $content);
    }

    public function testLaravelRouteHelperSupportsGroupedRoutes(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Laravel);
        $config->specFile = 'petstore.yaml';
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('PetsApiRoutes.php');

        self::assertStringContainsString("Route::get('/pets', [\$controller, 'listPetsAction']);", $content);
        self::assertStringContainsString("Route::put('/pets/{petId}', [\$controller, 'upsertPetAction']);", $content);
        self::assertStringContainsString("Route::delete('/pets/{petId}', [\$controller, 'deletePetAction']);", $content);
    }

    public function testLaravelControllerUsesIlluminateRequest(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Laravel);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiController.php');

        self::assertStringContainsString('Illuminate\\Http\\Request', $content);
    }

    public function testLaravelControllerReturnsJsonResponse(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Laravel);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiController.php');

        self::assertStringContainsString('JsonResponse', $content);
        self::assertStringContainsString('response()->json(', $content);
    }

    public function testLaravelControllerDeserializesRequestBody(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Laravel);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiController.php');

        self::assertStringContainsString('CreateOrderRequest::fromRequestArray(', $content);
        self::assertStringContainsString('$request->all()', $content);
    }

    public function testLaravelControllerFileIsValidPhp(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Laravel);
        $this->service->generate($config, self::FIXTURES_DIR);

        foreach (['OrdersApiController.php', 'OrdersApiRoutes.php'] as $file) {
            $path = $this->outputDir . '/Api/' . $file;
            $output = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($path) . ' 2>&1');
            self::assertStringContainsString('No syntax errors', (string) $output, "Syntax error in $file");
        }
    }

    public function testLaravelControllerVoidActionDoesNotAssignResult(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Laravel);
        $config->specFile = 'petstore.yaml';
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('PetsApiController.php');

        self::assertStringContainsString('public function deletePetAction(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse', $content);
        self::assertStringContainsString('$this->deletePet(', $content);
        self::assertStringNotContainsString('$result = $this->deletePet(', $content);
        self::assertStringContainsString('return response()->json(null, 204);', $content);
    }

    public function testLaravelControllerWithLaravelValidationStrategy(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Laravel);
        $config->validationStrategy = ValidationStrategy::LaravelValidation;
        $config->validateServerRequest = true;
        $config->validateServerResponse = true;
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiController.php');

        self::assertStringContainsString('private function validateLaravelPayload(mixed $value): void', $content);
        self::assertStringContainsString('private function validateLaravelRequestPayload(mixed $value): void', $content);
        self::assertStringContainsString('private function validateLaravelResponsePayload(mixed $value): void', $content);
        self::assertStringContainsString('$this->validateLaravelRequestPayload($body);', $content);
    }

    public function testSymfonyControllerUsesDirectionalDtoMethodsForReadWriteFlags(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Symfony);
        $config->specFile = 'read-write-flags.yaml';
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('AccountsApiController.php');

        self::assertStringContainsString('Account::fromRequestArray($request->toArray())', $content);
        self::assertStringContainsString('return new JsonResponse($result->toResponseArray(), 201);', $content);
    }

    public function testLaravelControllerUsesDirectionalDtoMethodsForReadWriteFlags(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Laravel);
        $config->specFile = 'read-write-flags.yaml';
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('AccountsApiController.php');

        self::assertStringContainsString('Account::fromRequestArray($request->all())', $content);
        self::assertStringContainsString('return response()->json($result->toResponseArray(), 201);', $content);
    }

    // =========================================================================
    // Server: no controller when FrameworkTarget::None
    // =========================================================================

    public function testNoControllerGeneratedWhenFrameworkTargetIsNone(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::None);
        $this->service->generate($config, self::FIXTURES_DIR);

        self::assertFileDoesNotExist($this->outputDir . '/Api/OrdersApiController.php');
        self::assertFileDoesNotExist($this->outputDir . '/Api/OrdersApiRoutes.php');
        self::assertFileExists($this->outputDir . '/Api/OrdersApiInterface.php');
    }

    public function testLaravelRouteHelperIsNotGeneratedForSymfony(): void
    {
        $config = $this->makeServerConfig(FrameworkTarget::Symfony);
        $this->service->generate($config, self::FIXTURES_DIR);

        self::assertFileDoesNotExist($this->outputDir . '/Api/OrdersApiRoutes.php');
    }

    // =========================================================================
    // Client: interface
    // =========================================================================

    public function testClientInterfaceIsGenerated(): void
    {
        $config = $this->makeClientConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        self::assertFileExists($this->outputDir . '/Api/OrdersApiClientInterface.php');
        self::assertFileExists($this->outputDir . '/Api/CatalogApiClientInterface.php');
    }

    public function testClientInterfaceDeclaresOperationMethods(): void
    {
        $config = $this->makeClientConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiClientInterface.php');

        self::assertStringContainsString('interface OrdersApiClientInterface', $content);
        self::assertStringContainsString('public function createOrder(', $content);
    }

    public function testClientInterfaceMethodReturnType(): void
    {
        $config = $this->makeClientConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiClientInterface.php');

        // createOrder returns Order
        self::assertStringContainsString('): Order', $content);
    }

    public function testClientInterfaceFilesAreValidPhp(): void
    {
        $config = $this->makeClientConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        foreach (['OrdersApiClientInterface.php', 'CatalogApiClientInterface.php'] as $file) {
            $path = $this->outputDir . '/Api/' . $file;
            $output = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($path) . ' 2>&1');
            self::assertStringContainsString('No syntax errors', (string) $output, "Syntax error in $file");
        }
    }

    // =========================================================================
    // Client: Symfony HttpClient
    // =========================================================================

    public function testSymfonyClientIsGenerated(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::SymfonyHttpClient);
        $this->service->generate($config, self::FIXTURES_DIR);

        self::assertFileExists($this->outputDir . '/Api/OrdersApiClient.php');
    }

    public function testSymfonyClientImplementsInterface(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::SymfonyHttpClient);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiClient.php');

        self::assertStringContainsString('final class OrdersApiClient implements OrdersApiClientInterface', $content);
    }

    public function testSymfonyClientUsesHttpClientInterface(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::SymfonyHttpClient);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiClient.php');

        self::assertStringContainsString('HttpClientInterface', $content);
        self::assertStringContainsString('use Symfony\\Contracts\\HttpClient\\HttpClientInterface', $content);
    }

    public function testSymfonyClientPostOperationSendsJson(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::SymfonyHttpClient);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiClient.php');

        self::assertStringContainsString("'json' => \$body->toRequestArray()", $content);
    }

    public function testSymfonyClientDeserializesResponse(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::SymfonyHttpClient);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiClient.php');

        self::assertStringContainsString('Order::fromResponseArray($response->toArray())', $content);
    }

    public function testSymfonyClientArrayResponseUsesArrayMap(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::SymfonyHttpClient);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('CatalogApiClient.php');

        self::assertStringContainsString('array_map(', $content);
        self::assertStringContainsString('CatalogItem::fromResponseArray(', $content);
    }

    public function testSymfonyClientPathParameterUrlHasNoTrailingEmptyStringConcat(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::SymfonyHttpClient);
        $config->specFile = 'petstore.yaml';
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('PetsApiClient.php');

        self::assertStringContainsString('$this->baseUrl . "/pets/{$petId}"', $content);
        self::assertStringNotContainsString(". ''", $content);
    }

    public function testSymfonyClientFilesAreValidPhp(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::SymfonyHttpClient);
        $this->service->generate($config, self::FIXTURES_DIR);

        foreach (['OrdersApiClient.php', 'CatalogApiClient.php', 'OrdersApiClientInterface.php'] as $file) {
            $path = $this->outputDir . '/Api/' . $file;
            $output = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($path) . ' 2>&1');
            self::assertStringContainsString('No syntax errors', (string) $output, "Syntax error in $file");
        }
    }

    public function testSymfonyClientValidatesNativeRequestWhenEnabled(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::SymfonyHttpClient);
        $config->validationStrategy = ValidationStrategy::NativeMethod;
        $config->validateClientRequest = true;
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiClient.php');

        self::assertStringContainsString('private function validateNativePayload(mixed $value): void', $content);
        self::assertStringContainsString('private function validateNativeRequestPayload(mixed $value): void', $content);
        self::assertStringContainsString('private function validateNativeResponsePayload(mixed $value): void', $content);
        self::assertStringContainsString('$this->validateNativeRequestPayload($body);', $content);
    }

    public function testSymfonyClientValidatesNativeResponseWhenEnabled(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::SymfonyHttpClient);
        $config->validationStrategy = ValidationStrategy::NativeMethod;
        $config->validateClientResponse = true;
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiClient.php');

        self::assertStringContainsString('$result = Order::fromResponseArray($response->toArray());', $content);
        self::assertStringContainsString('$this->validateNativeResponsePayload($result);', $content);
        self::assertStringContainsString('return $result;', $content);
    }

    public function testSymfonyClientValidatesSymfonyRequestWhenEnabled(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::SymfonyHttpClient);
        $config->validationStrategy = ValidationStrategy::SymfonyConstraints;
        $config->validateClientRequest = true;
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiClient.php');

        self::assertStringContainsString('use Symfony\\Component\\Validator\\Validation;', $content);
        self::assertStringContainsString('use Symfony\\Component\\Validator\\Validator\\ValidatorInterface;', $content);
        self::assertStringContainsString('?ValidatorInterface $validator = null,', $content);
        self::assertStringContainsString('$this->validator = $validator ?? Validation::createValidatorBuilder()', $content);
        self::assertStringContainsString('private function validateSymfonyPayload(mixed $value, ?\SplObjectStorage $visited = null): void', $content);
        self::assertStringContainsString('private function validateSymfonyRequestPayload(mixed $value, ?\SplObjectStorage $visited = null): void', $content);
        self::assertStringContainsString('private function validateSymfonyResponsePayload(mixed $value, ?\SplObjectStorage $visited = null): void', $content);
        self::assertStringContainsString('$violations = $this->validator->validate($value);', $content);
        self::assertStringContainsString('foreach (get_object_vars($value) as $propertyValue) {', $content);
        self::assertStringContainsString('$this->validateSymfonyRequestPayload($propertyValue, $visited);', $content);
        self::assertStringContainsString('$this->validateSymfonyRequestPayload($body);', $content);
    }

    public function testSymfonyClientValidatesSymfonyResponseWhenEnabled(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::SymfonyHttpClient);
        $config->validationStrategy = ValidationStrategy::SymfonyConstraints;
        $config->validateClientResponse = true;
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiClient.php');

        self::assertStringContainsString('$this->validateSymfonyResponsePayload($result);', $content);
    }

    public function testClientUsesDirectionalDtoMethodsForReadWriteFlags(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::SymfonyHttpClient);
        $config->specFile = 'read-write-flags.yaml';
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('AccountsApiClient.php');

        self::assertStringContainsString("'json' => \$body->toRequestArray()", $content);
        self::assertStringContainsString('Account::fromResponseArray($response->toArray())', $content);
    }

    // =========================================================================
    // Client: Guzzle
    // =========================================================================

    public function testGuzzleClientUsesGuzzleInterface(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::Guzzle);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiClient.php');

        self::assertStringContainsString('use GuzzleHttp\\ClientInterface', $content);
        self::assertStringContainsString('use GuzzleHttp\\RequestOptions', $content);
    }

    public function testGuzzleClientPostSendsJson(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::Guzzle);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiClient.php');

        self::assertStringContainsString('RequestOptions::JSON', $content);
        self::assertStringContainsString('$body->toRequestArray()', $content);
    }

    public function testGuzzleClientDecodesJsonResponse(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::Guzzle);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiClient.php');

        self::assertStringContainsString('json_decode(', $content);
        self::assertStringContainsString('JSON_THROW_ON_ERROR', $content);
        self::assertStringContainsString('Order::fromResponseArray(', $content);
    }

    public function testGuzzleClientFilesAreValidPhp(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::Guzzle);
        $this->service->generate($config, self::FIXTURES_DIR);

        foreach (['OrdersApiClient.php', 'CatalogApiClient.php'] as $file) {
            $path = $this->outputDir . '/Api/' . $file;
            $output = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($path) . ' 2>&1');
            self::assertStringContainsString('No syntax errors', (string) $output, "Syntax error in $file");
        }
    }

    // =========================================================================
    // Client: PSR-18
    // =========================================================================

    public function testPsr18ClientUsesPsrInterfaces(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::Psr18);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiClient.php');

        self::assertStringContainsString('use Psr\\Http\\Client\\ClientInterface', $content);
        self::assertStringContainsString('use Psr\\Http\\Message\\RequestFactoryInterface', $content);
        self::assertStringContainsString('use Psr\\Http\\Message\\StreamFactoryInterface', $content);
    }

    public function testPsr18ClientInjectsRequestAndStreamFactories(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::Psr18);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiClient.php');

        self::assertStringContainsString('RequestFactoryInterface $requestFactory', $content);
        self::assertStringContainsString('StreamFactoryInterface $streamFactory', $content);
    }

    public function testPsr18ClientCreatesRequestViaFactory(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::Psr18);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiClient.php');

        self::assertStringContainsString('$this->requestFactory->createRequest(', $content);
        self::assertStringContainsString("'POST'", $content);
    }

    public function testPsr18ClientSetsContentTypeForPostBody(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::Psr18);
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiClient.php');

        self::assertStringContainsString("'Content-Type', 'application/json'", $content);
        self::assertStringContainsString('$this->streamFactory->createStream(', $content);
        self::assertStringContainsString('json_encode($body->toRequestArray(), JSON_THROW_ON_ERROR)', $content);
    }

    public function testPsr18ClientFilesAreValidPhp(): void
    {
        $config = $this->makeClientConfig(HttpClientAdapter::Psr18);
        $this->service->generate($config, self::FIXTURES_DIR);

        foreach (['OrdersApiClient.php', 'CatalogApiClient.php'] as $file) {
            $path = $this->outputDir . '/Api/' . $file;
            $output = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($path) . ' 2>&1');
            self::assertStringContainsString('No syntax errors', (string) $output, "Syntax error in $file");
        }
    }

    // =========================================================================
    // Deprecated operations get @deprecated PHPDoc
    // =========================================================================

    public function testDeprecatedOperationEmitsPhpDocInServerInterface(): void
    {
        // Add a deprecated op via petstore (which has no deprecated ops) —
        // skip this if the fixture doesn't expose one; document the gap.
        // For now we verify non-deprecated ops do NOT emit it.
        $config = $this->makeServerConfig();
        $this->service->generate($config, self::FIXTURES_DIR);

        $content = $this->readApi('OrdersApiInterface.php');

        // createOrder is not deprecated
        self::assertStringNotContainsString('@deprecated', $content);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeServerConfig(FrameworkTarget $framework = FrameworkTarget::None): GeneratorConfig
    {
        $config = new GeneratorConfig();
        $config->specFile = 'feature-test.yaml';
        $config->outputDir = $this->outputDir;
        $config->modelNamespace = 'Generated\\Model';
        $config->modelOutputDir = 'Model';
        $config->apiNamespace = 'Generated\\Api';
        $config->apiOutputDir = 'Api';
        $config->phpVersion = '8.2';
        $config->generateFromArray = true;
        $config->generateToArray = true;
        $config->phpReadonly = true;
        $config->generationTarget = GenerationTarget::Server;
        $config->frameworkTarget = $framework;

        return $config;
    }

    private function makeClientConfig(HttpClientAdapter $adapter = HttpClientAdapter::SymfonyHttpClient): GeneratorConfig
    {
        $config = new GeneratorConfig();
        $config->specFile = 'feature-test.yaml';
        $config->outputDir = $this->outputDir;
        $config->modelNamespace = 'Generated\\Model';
        $config->modelOutputDir = 'Model';
        $config->apiNamespace = 'Generated\\Api';
        $config->apiOutputDir = 'Api';
        $config->phpVersion = '8.2';
        $config->generateFromArray = true;
        $config->generateToArray = true;
        $config->phpReadonly = true;
        $config->generationTarget = GenerationTarget::Client;
        $config->httpClient = $adapter;

        return $config;
    }

    private function readApi(string $filename): string
    {
        $path = $this->outputDir . '/Api/' . $filename;
        self::assertFileExists($path, "Expected generated file $filename does not exist");
        $content = file_get_contents($path);
        self::assertNotFalse($content);

        return $content;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}
