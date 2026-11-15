<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class TxInMemoryTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/write-tx-in-memory-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/write-tx-in-memory-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/write-tx-in-memory-log';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        TxKernelConfig::apply($container, $builder);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(TxWidgetResource::class);
        $services->set(TxNoteResource::class);

        $services->set('test.tx_widgets_provider', InMemoryDataProvider::class)
            ->factory([TxFactory::class, 'createWidgets'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.tx_notes_provider', InMemoryDataProvider::class)
            ->factory([TxFactory::class, 'createNotes'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.tx_widgets_persister', InMemoryDataPersister::class)
            ->factory([TxFactory::class, 'createWidgetsPersister']);
        $services->set(TxAcceptingPersister::class)
            ->arg('$inner', service('test.tx_widgets_persister'))
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG, ['priority' => 10]);
        $services->set('test.tx_notes_persister', InMemoryDataPersister::class)
            ->factory([TxFactory::class, 'createNotesPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        TxKernelConfig::services($services);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
