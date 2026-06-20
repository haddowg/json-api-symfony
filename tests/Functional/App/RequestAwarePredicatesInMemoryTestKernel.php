<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BadgeResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\MedalResource;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The in-memory half of the request-aware-predicates conformance pair (bundle ADR
 * 0084): the {@see BadgeResource} (every request-aware predicate, keyed off the
 * inbound `X-Role` header) and the related {@see MedalResource} over a writable
 * in-memory graph ({@see RequestAwarePredicatesFactory}), with an
 * {@see InMemoryDataPersister} for `badges` so the gated relationship mutations
 * resolve linkage ids and a follow-up read sees the change. Distinct cache/project
 * dirs keep its compiled container from colliding with the other kernels'.
 */
final class RequestAwarePredicatesInMemoryTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/request-aware-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/request-aware-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/request-aware-log';
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

        $services->set(BadgeResource::class);
        $services->set(MedalResource::class);

        $services->set('test.badges_provider', InMemoryDataProvider::class)
            ->factory([RequestAwarePredicatesFactory::class, 'createBadges'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.medals_provider', InMemoryDataProvider::class)
            ->factory([RequestAwarePredicatesFactory::class, 'createMedals'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.badges_persister', InMemoryDataPersister::class)
            ->factory([RequestAwarePredicatesFactory::class, 'createBadgesPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
