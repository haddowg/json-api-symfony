<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\CountThrough;

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
 * Serves the campaign graph from in-memory providers.
 */
final class CountThroughInMemoryTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        return CountThroughKernelConfig::directory('count-through-memory-app');
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/count-through-memory-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/count-through-memory-log';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        CountThroughKernelConfig::apply($container, $builder);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(BrandResource::class);
        $services->set(TemplateResource::class);
        $services->set(CampaignResource::class);
        $services->set(LeadResource::class);

        foreach (['ctBrands' => 'brands', 'ctTemplates' => 'templates', 'ctCampaigns' => 'campaigns', 'ctLeads' => 'leads'] as $type => $slice) {
            $services->set('test.ct_' . $slice . '_provider', InMemoryDataProvider::class)
                ->factory([CountThroughProviderFactory::class, 'provider'])
                ->args([$type, $slice])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
