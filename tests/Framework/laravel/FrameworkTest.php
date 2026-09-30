<?php

declare(strict_types=1);

namespace FrameworkLaravel;

use FrameworkFixture\Api\WidgetsApiInterface;
use FrameworkFixture\Api\WidgetsApiRoutes;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

final class FrameworkTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('OPENAPI_GEN_FRAMEWORK_TESTS') !== '1') {
            self::markTestSkipped('Set OPENAPI_GEN_FRAMEWORK_TESTS=1 to run the isolated framework suite.');
        }
        frameworkFixture('laravel');
        require_once __DIR__ . '/WidgetController.php';
        parent::setUp();
        $this->app->bind(WidgetsApiInterface::class, WidgetController::class);
        $this->app->bind(WidgetController::class);
        Route::prefix('v1')->group(static fn () => WidgetsApiRoutes::register(WidgetController::class));
    }

    public function testGeneratedRoutesAndContainerBinding(): void
    {
        self::assertInstanceOf(WidgetController::class, $this->app->make(WidgetsApiInterface::class));
        Artisan::call('route:list', ['--json' => true]);
        $routes = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        foreach ([
            ['GET|HEAD', 'v1/widgets/{widgetId}', 'getWidgetAction'],
            ['POST', 'v1/widgets', 'createWidgetAction'],
            ['PUT', 'v1/widgets/{widgetId}', 'updateWidgetAction'],
            ['DELETE', 'v1/widgets/{widgetId}', 'deleteWidgetAction'],
        ] as [$method, $uri, $action]) {
            self::assertNotEmpty(array_filter(
                $routes,
                static fn (array $route): bool => $route['uri'] === $uri
                && str_contains($route['method'], $method)
                && str_contains($route['action'], WidgetController::class . '@' . $action),
            ), "Missing route $method $uri");
        }
    }

    public function testFunctionalRequestsAndValidation(): void
    {
        $this->getJson('/v1/widgets/item-1')->assertOk()->assertExactJson(['id' => 'item-1', 'name' => 'Found']);
        $this->postJson('/v1/widgets', ['name' => 'New'])->assertCreated()
            ->assertExactJson(['id' => 'created', 'name' => 'New']);
        $this->putJson('/v1/widgets/item-1', ['name' => 'Changed'])->assertOk()
            ->assertExactJson(['id' => 'item-1', 'name' => 'Changed']);
        $this->deleteJson('/v1/widgets/item-1')->assertNoContent();
        $this->postJson('/v1/widgets', ['name' => ''])->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['name']]);
    }
}
