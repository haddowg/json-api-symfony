<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Hook\HookableWidgetResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Hook\HookOwnerResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Hook\HookWidgetFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Hook\HookWidgetResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Hook\RecordingHookSubscriber;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The lifecycle-hooks witness kernel (bundle ADR 0042): two equivalent widget
 * types over the in-memory provider/persister — `hookWidgets` observed through the
 * cross-cutting {@see RecordingHookSubscriber} (the **event** mechanism) and
 * `hookableWidgets` whose resource implements
 * {@see \haddowg\JsonApiBundle\Hook\ResourceLifecycleHooksInterface} (the
 * **resource-method** mechanism, routed by the built-in
 * {@see \haddowg\JsonApiBundle\EventListener\ResourceHookSubscriber}) — plus a
 * related `hookOwners` type so the relationship-mutation hooks have a target. The
 * {@see RecordingHookSubscriber} is autoconfigured as a kernel event subscriber.
 */
final class LifecycleHooksTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/lifecycle-hooks-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/lifecycle-hooks-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/lifecycle-hooks-log';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $container->extension('framework', [
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
        ]);

        $container->extension('json_api', [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(HookWidgetResource::class);
        $services->set(HookableWidgetResource::class);
        $services->set(HookOwnerResource::class);

        // The application event subscriber proving the event mechanism on the
        // `hookWidgets` type (autoconfigured to kernel.event_subscriber).
        $services->set(RecordingHookSubscriber::class);

        $services->set('test.hook_widgets_provider', InMemoryDataProvider::class)
            ->factory([HookWidgetFactory::class, 'createHookWidgets'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.hook_widgets_persister', InMemoryDataPersister::class)
            ->factory([HookWidgetFactory::class, 'createHookWidgetsPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.hookable_widgets_provider', InMemoryDataProvider::class)
            ->factory([HookWidgetFactory::class, 'createHookableWidgets'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.hookable_widgets_persister', InMemoryDataPersister::class)
            ->factory([HookWidgetFactory::class, 'createHookableWidgetsPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.hook_owners_provider', InMemoryDataProvider::class)
            ->factory([HookWidgetFactory::class, 'createOwners'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
