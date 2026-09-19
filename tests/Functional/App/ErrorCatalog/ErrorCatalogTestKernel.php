<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ErrorCatalog;

use haddowg\JsonApi\Exception\ClassListErrorSource;
use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Routing\OpenApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\ErrorCatalog\Unscanned\TenantSuspended;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The application-contributed error-code harness. It wires all three cases the seam has
 * to tell apart, over two sibling directories that are equally "somewhere under the
 * project":
 *
 *  - `ErrorCatalog/Scanned` is the one directory `error_codes.paths` names, so
 *    {@see Scanned\QuotaExceeded} is catalogued by the scan alone;
 *  - {@see TenantSuspended} lives outside it and is named by a tagged
 *    {@see ClassListErrorSource}, so it is catalogued by registration;
 *  - {@see Unscanned\SubscriptionRequired} lives beside it, registered nowhere, so it
 *    is catalogued not at all.
 *
 * `expose_in_prod: true` so `GET /docs.json` is routed under the functional suite's
 * `debug=false` boot.
 */
final class ErrorCatalogTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/error-catalog-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/error-catalog-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/error-catalog-log';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
        ]);

        $container->extension('json_api', [
            'base_uri' => 'https://ledger.test',
            'version' => '1.1',
            'error_codes' => [
                'paths' => [__DIR__ . '/Scanned'],
            ],
            'openapi' => [
                'expose_in_prod' => true,
                'info' => ['title' => 'Ledger API', 'version' => '1.0.0'],
            ],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set('logger', \Psr\Log\NullLogger::class);

        $services->set(LedgerAccountResource::class);

        $services->set('test.error_catalog.accounts_provider', InMemoryDataProvider::class)
            ->factory([self::class, 'createAccountsProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        // The type exposes writes, so core's write-gated codes stay in the catalogue the
        // contributed ones join.
        $services->set('test.error_catalog.accounts_persister', InMemoryDataPersister::class)
            ->factory([self::class, 'createAccountsPersister'])
            ->args([service('test.error_catalog.accounts_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        // The escape hatch: a core ClassListErrorSource naming a class no scan reaches.
        // autoconfigure() tags it (it implements ErrorCatalogSourceInterface), which is
        // all an application has to do.
        $services->set('test.error_catalog.explicit_source', ClassListErrorSource::class)
            ->autowire(false)
            ->args([TenantSuspended::class]);
    }

    public static function createAccountsProvider(): InMemoryDataProvider
    {
        $accounts = ['la1' => new LedgerAccount('la1', 'Operating')];

        return new InMemoryDataProvider('ledgerAccounts', $accounts, static function (object $item): string {
            \assert($item instanceof LedgerAccount);

            return $item->id;
        });
    }

    public static function createAccountsPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('ledgerAccounts', $provider->store(), static fn(): LedgerAccount => new LedgerAccount());
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $routes->import('.', OpenApiRouteLoader::ROUTE_TYPE);
    }
}
