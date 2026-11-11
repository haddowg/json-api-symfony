<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedProviderBatch;

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
 * Serves the runs from an in-memory provider.
 */
final class RelatedProviderBatchInMemoryTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        return RelatedProviderBatchKernelConfig::directory('related-provider-batch-memory-app');
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/related-provider-batch-memory-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/related-provider-batch-memory-log';
    }

    public static function runsProvider(): InMemoryDataProvider
    {
        $runs = [];
        foreach (RunFixtures::runs() as $run) {
            $runs[(string) $run->id] = $run;
        }

        return new InMemoryDataProvider('runs', $runs);
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        RelatedProviderBatchKernelConfig::apply($container, $builder);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(RunResource::class);
        RelatedProviderBatchKernelConfig::relatedTypes($services);

        $services->set('test.rpb_runs_provider', InMemoryDataProvider::class)
            ->factory([self::class, 'runsProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
