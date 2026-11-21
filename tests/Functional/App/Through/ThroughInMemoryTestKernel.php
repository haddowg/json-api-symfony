<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Through;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Routing\OpenApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\ThroughInKernelConfig;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Serves the lead graph from in-memory providers, the advertisers from a custom provider
 * that scopes the hidden one away. With {@see $broken} it also registers
 * {@see BrokenThrResource}.
 */
final class ThroughInMemoryTestKernel extends Kernel
{
    use MicroKernelTrait;

    public static ?string $broken = null;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        return ThroughInKernelConfig::directory('through-relations-memory-app');
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/through-relations-memory-cache/' . (self::$broken ?? 'ok') . '-' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/through-relations-memory-log';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        ThroughInKernelConfig::apply($container, $builder);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(ThrAdvertiserResource::class);
        $services->set(ThrTemplateResource::class);
        $services->set(ThrCampaignResource::class);
        $services->set(ThrLeadResource::class);

        foreach (['thrTemplates' => 'templates', 'thrCampaigns' => 'campaigns', 'thrLeads' => 'leads'] as $type => $slice) {
            $services->set('test.thr_' . $slice . '_provider', InMemoryDataProvider::class)
                ->factory([ThroughProviderFactory::class, 'provider'])
                ->args([$type, $slice])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        }
        $services->set('test.thr_advertisers_provider', ScopedThrAdvertiserProvider::class)
            ->factory([ThroughProviderFactory::class, 'advertisers'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        if (self::$broken !== null) {
            BaseBrokenThrResource::$declaration = self::$broken;
            $services->set(BrokenThrResource::class);
            $services->set('test.thr_broken_provider', InMemoryDataProvider::class)
                ->factory([ThroughProviderFactory::class, 'provider'])
                ->args(['thrBroken', 'leads'])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $routes->import('.', OpenApiRouteLoader::ROUTE_TYPE);
    }
}
