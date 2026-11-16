<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Inbox;

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
 * Serves the inbox from in-memory providers.
 */
final class InboxInMemoryTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        return ThroughInKernelConfig::directory('inbox-memory-app');
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/inbox-memory-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/inbox-memory-log';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        ThroughInKernelConfig::apply($container, $builder);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(RfpResource::class);
        $services->set(InboxCampaignResource::class);
        $services->set(InboxFolderResource::class);
        $services->set(InboxItemResource::class);
        $services->set(TallyInboxItems::class);

        foreach (['inbRfps' => 'rfps', 'inbCampaigns' => 'campaigns', 'inbFolders' => 'folders', 'inbItems' => 'items'] as $type => $slice) {
            $services->set('test.inb_' . $slice . '_provider', InMemoryDataProvider::class)
                ->factory([InboxProviderFactory::class, 'provider'])
                ->args([$type, $slice])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $routes->import('.', OpenApiRouteLoader::ROUTE_TYPE);
    }
}
