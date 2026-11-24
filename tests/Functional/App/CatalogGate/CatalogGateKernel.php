<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\CatalogGate;

use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\PinnedConfigDefaults;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\AccessTokenHandler;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Hubs and the spokes and rims they relate to, on in-memory providers behind a
 * Bearer-token firewall, so the catalogue's verdicts can be compared with the answers
 * the related and relationship endpoints give.
 */
final class CatalogGateKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/catalog-gate-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/catalog-gate-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/catalog-gate-log';
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
            'base_uri' => 'https://example.test',
            'version' => '1.1',
        ]);
        $container->extension('security', [
            'providers' => [
                'in_memory' => [
                    'memory' => [
                        'users' => [
                            'admin' => ['roles' => ['ROLE_ADMIN', 'ROLE_USER']],
                            'ada' => ['roles' => ['ROLE_USER']],
                        ],
                    ],
                ],
            ],
            'firewalls' => [
                'main' => [
                    'pattern' => '^/',
                    'stateless' => true,
                    'provider' => 'in_memory',
                    'access_token' => [
                        'token_handler' => AccessTokenHandler::class,
                    ],
                ],
            ],
        ]);

        $builder->register('logger', \Psr\Log\NullLogger::class);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(AccessTokenHandler::class);
        foreach ([HubResource::class, OwnedHubResource::class, SpokeResource::class, RimResource::class] as $class) {
            $services->set($class);
        }

        foreach (['hubs', 'ownedHubs', 'spokes', 'rims'] as $type) {
            $services->set('test.catalog_gate_provider.' . $type, \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider::class)
                ->autoconfigure(false)
                ->factory([CatalogGateFixtures::class, 'provider'])
                ->args([$type])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
