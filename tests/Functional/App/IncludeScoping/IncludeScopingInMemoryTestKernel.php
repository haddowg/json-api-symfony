<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

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

/**
 * The in-memory twin of {@see IncludeScopingProviderTestKernel}: the same types on
 * the same two servers, served from {@see ScopingFixtures}, the agency and member
 * types through {@see HidingProvider}. The `opaque-` types are served by
 * {@see OpaqueScopedProvider}, which neither batches nor declares its scope.
 */
final class IncludeScopingInMemoryTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/include-scoping-memory-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/include-scoping-memory-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/include-scoping-memory-log';
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
            'servers' => [
                'partner' => ['base_uri' => 'https://partner.test'],
            ],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        // The refused reads log an error; keep it off the test output.
        $builder->register('logger', \Psr\Log\NullLogger::class);

        $services->set(InMemoryDefaultScopingAdvertiserResource::class);
        $services->set(InMemoryDefaultScopingAgencyResource::class);
        $services->set(InMemoryDefaultScopingMemberResource::class);
        $services->set(InMemoryPartnerScopingAdvertiserResource::class);
        $services->set(InMemoryPartnerScopingAgencyResource::class);
        $services->set(InMemoryPartnerScopingMemberResource::class);

        $services->set(OpaqueAdvertiserResource::class);
        $services->set(OpaqueAgencyResource::class);
        $services->set(OpaqueMemberResource::class);

        foreach (['advertisers' => 'opaque-advertisers', 'agencies' => 'opaque-agencies', 'members' => 'opaque-members'] as $base => $type) {
            $services->set('test.opaque_provider.' . $type, OpaqueScopedProvider::class)
                ->factory([OpaqueScopedProvider::class, 'create'])
                ->args([$type, $base])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        }

        foreach (['advertisers', 'partner-advertisers'] as $type) {
            $services->set('test.scoping_provider.' . $type, InMemoryDataProvider::class)
                ->factory([ScopingFixtures::class, 'inMemoryProvider'])
                ->args([$type])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        }

        foreach (['agencies', 'members', 'partner-agencies', 'partner-members'] as $type) {
            $services->set('test.scoping_provider.' . $type, HidingProvider::class)
                ->autoconfigure(false)
                ->factory([HidingProvider::class, 'inMemory'])
                ->args([$type])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        }

        foreach (['members', 'partner-members'] as $type) {
            $services->set('test.scoping_persister.' . $type, \haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister::class)
                ->factory([ScopingFixtures::class, 'inMemoryPersister'])
                ->args([$type])
                ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
        }

        $services->alias('test.scoping_include_batcher', \haddowg\JsonApiBundle\DataProvider\RelatedIncludeBatcher::class)->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $routes->import('partner', JsonApiRouteLoader::ROUTE_TYPE)->prefix('/partner');
    }
}
