<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OperationErrors;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Routing\OpenApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\ErrorCatalog\LedgerAccount;
use haddowg\JsonApiBundle\Tests\Functional\App\PinnedConfigDefaults;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * One `accounts` type declaring a code for its update, plus an action declaring its own,
 * so the served document shows each code offered only where it is declared.
 */
final class OperationErrorsTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/operation-errors-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/operation-errors-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/operation-errors-log';
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
        PinnedConfigDefaults::apply($container, $builder);

        $container->extension('json_api', [
            'base_uri' => 'https://ledger.test',
            'version' => '1.1',
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

        $services->set(ReviewedAccountResource::class);
        $services->set(FreezeAccount::class);

        $services->set('test.operation_errors.accounts_provider', InMemoryDataProvider::class)
            ->factory([self::class, 'createAccountsProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.operation_errors.accounts_persister', InMemoryDataPersister::class)
            ->factory([self::class, 'createAccountsPersister'])
            ->args([service('test.operation_errors.accounts_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    public static function createAccountsProvider(): InMemoryDataProvider
    {
        $accounts = ['a1' => new LedgerAccount('a1', 'Operating')];

        return new InMemoryDataProvider('accounts', $accounts, static function (object $item): string {
            \assert($item instanceof LedgerAccount);

            return $item->id;
        });
    }

    public static function createAccountsPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('accounts', $provider->store(), static fn(): LedgerAccount => new LedgerAccount());
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $routes->import('.', OpenApiRouteLoader::ROUTE_TYPE);
    }
}
