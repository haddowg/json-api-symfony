<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Routing\OpenApiRouteLoader;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Serves the lead graph from in-memory providers. With {@see $polymorphicLeaf} it also
 * registers {@see PolymorphicLeafResource}, whose filter cannot resolve.
 */
final class ThroughInInMemoryTestKernel extends Kernel
{
    use MicroKernelTrait;

    public static bool $polymorphicLeaf = false;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        return ThroughInKernelConfig::directory('through-in-memory-app');
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/through-in-memory-cache/' . (self::$polymorphicLeaf ? 'polymorphic-' : '') . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/through-in-memory-log';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        ThroughInKernelConfig::apply($container, $builder);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(AdvertiserResource::class);
        $services->set(TemplateResource::class);
        $services->set(CampaignResource::class);
        $services->set(LeadResource::class);

        foreach (['wtiAdvertisers' => 'advertisers', 'wtiTemplates' => 'templates', 'wtiCampaigns' => 'campaigns', 'wtiLeads' => 'leads'] as $type => $slice) {
            $services->set('test.wti_' . $slice . '_provider', InMemoryDataProvider::class)
                ->factory([ThroughInProviderFactory::class, 'provider'])
                ->args([$type, $slice])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        }

        if (self::$polymorphicLeaf) {
            $services->set(PolymorphicLeafResource::class);
            $services->set('test.wti_mentions_provider', InMemoryDataProvider::class)
                ->factory([ThroughInProviderFactory::class, 'provider'])
                ->args(['wtiMentions', 'mentions'])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $routes->import('.', OpenApiRouteLoader::ROUTE_TYPE);
    }
}
