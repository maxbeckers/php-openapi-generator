<?php

declare(strict_types=1);

namespace FrameworkSymfony;

use FrameworkFixture\Api\WidgetsApiInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
    }

    public function getCacheDir(): string
    {
        return frameworkFixture('symfony') . '/cache';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'framework-fixture',
            'test' => true,
            'router' => ['utf8' => true],
        ]);
        $services = $container->services();
        $services->set(WidgetController::class)->public();
        $services->alias(WidgetsApiInterface::class, WidgetController::class)->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(__DIR__ . '/WidgetController.php', 'attribute');
    }
}
