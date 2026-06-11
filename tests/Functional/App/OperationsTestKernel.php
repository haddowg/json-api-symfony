<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\LedgerResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\SignalResource;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The operation-exposure witness kernel (ADR 0025): three types that each declare
 * a different operation allow-list, so the route loader's per-operation emission
 * can be asserted against the route collection.
 *
 *  - `ledgers` — a read-only resource ({@see LedgerResource}, FetchCollection +
 *    FetchOne): only GET routes, no persister.
 *  - `signals` — a create-only resource ({@see SignalResource}, Create): only
 *    POST /signals, with an in-memory provider/persister sharing one store so the
 *    POST persists.
 *  - `beacons` — a routed standalone serializer ({@see BeaconSerializer}, FetchOne):
 *    only GET /beacons/{id}, with a read-only provider.
 */
final class OperationsTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/operations-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/operations-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/operations-log';
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

        // Read-only resource: a provider, no persister.
        $services->set(LedgerResource::class);
        $services->set('test.ledgers_provider', InMemoryDataProvider::class)
            ->factory([OperationsFactory::class, 'createLedgersProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        // Create-only resource: a provider + persister over one shared store.
        $services->set(SignalResource::class);
        $services->set('test.signals_provider', InMemoryDataProvider::class)
            ->factory([OperationsFactory::class, 'createSignalsProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.signals_persister', InMemoryDataPersister::class)
            ->factory([OperationsFactory::class, 'createSignalsPersister'])
            ->args([service('test.signals_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        // Routed standalone serializer: a provider, no persister.
        $services->set(BeaconSerializer::class);
        $services->set('test.beacons_provider', InMemoryDataProvider::class)
            ->factory([OperationsFactory::class, 'createBeaconsProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
