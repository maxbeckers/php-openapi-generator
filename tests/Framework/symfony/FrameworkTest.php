<?php

declare(strict_types=1);

namespace FrameworkSymfony;

use FrameworkFixture\Api\WidgetsApiInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;

require_once __DIR__ . '/Kernel.php';

final class FrameworkTest extends TestCase
{
    private Kernel $kernel;
    private KernelBrowser $browser;

    protected function setUp(): void
    {
        if (getenv('OPENAPI_GEN_FRAMEWORK_TESTS') !== '1') {
            self::markTestSkipped('Set OPENAPI_GEN_FRAMEWORK_TESTS=1 to run the isolated framework suite.');
        }
        frameworkFixture('symfony');
        require_once __DIR__ . '/WidgetController.php';
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $this->browser = new KernelBrowser($this->kernel);
    }

    protected function tearDown(): void
    {
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    public function testGeneratedRoutesAndContainerBinding(): void
    {
        $container = $this->kernel->getContainer();
        self::assertInstanceOf(WidgetController::class, $container->get(WidgetsApiInterface::class));
        /** @var RouterInterface $router */
        $router = $container->get('router');
        $routes = $router->getRouteCollection();
        $expected = [
            'getWidgetAction' => ['GET', '/widgets/{widgetId}'],
            'createWidgetAction' => ['POST', '/widgets'],
            'updateWidgetAction' => ['PUT', '/widgets/{widgetId}'],
            'deleteWidgetAction' => ['DELETE', '/widgets/{widgetId}'],
        ];
        foreach ($expected as $action => [$method, $path]) {
            $route = null;
            foreach ($routes as $candidate) {
                if ($candidate->getDefault('_controller') === WidgetController::class . '::' . $action) {
                    $route = $candidate;
                    break;
                }
            }
            self::assertNotNull($route, "Missing generated route for $action");
            self::assertSame($path, $route->getPath());
            self::assertContains($method, $route->getMethods());
            $matched = $router->matchRequest(\Symfony\Component\HttpFoundation\Request::create(
                $path === '/widgets' ? '/widgets' : '/widgets/item-1',
                $method,
            ));
            self::assertSame(WidgetController::class . '::' . $action, $matched['_controller']);
        }
    }

    public function testRealHttpRequests(): void
    {
        $this->browser->request('GET', '/widgets/item-1');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertSame(['id' => 'item-1', 'name' => 'Found'], json_decode($this->browser->getResponse()->getContent(), true));

        $this->browser->request('POST', '/widgets', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":"New"}');
        self::assertSame(201, $this->browser->getResponse()->getStatusCode());
        self::assertSame(['id' => 'created', 'name' => 'New'], json_decode($this->browser->getResponse()->getContent(), true));

        $this->browser->request('PUT', '/widgets/item-1', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":"Changed"}');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertSame(['id' => 'item-1', 'name' => 'Changed'], json_decode($this->browser->getResponse()->getContent(), true));

        $this->browser->request('DELETE', '/widgets/item-1');
        self::assertSame(204, $this->browser->getResponse()->getStatusCode());
        self::assertSame('', $this->browser->getResponse()->getContent());
    }
}
