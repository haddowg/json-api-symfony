<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FieldSelection;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\PinnedConfigDefaults;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * A kernel serving the {@see PanelResource} and {@see CuratorResource} over the in-memory
 * provider, the field-selection conformance witness
 * ({@see \haddowg\JsonApiBundle\Tests\Functional\FieldSelectionConformanceTestCase}).
 */
final class FieldSelectionInMemoryTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/field-selection-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/field-selection-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/field-selection-log';
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
        PinnedConfigDefaults::apply($container, $builder);

        $container->extension('json_api', [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
            'atomic_operations' => ['enabled' => true],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(PanelResource::class);
        $services->set(CuratorResource::class);
        $services->set(BoardResource::class);
        $services->set(EaselResource::class);
        $services->set(BoardSerializer::class);
        $services->set(EaselSerializer::class);

        $services->set('test.boards_provider', InMemoryDataProvider::class)
            ->factory([FieldSelectionFactory::class, 'createBoards'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.easels_provider', InMemoryDataProvider::class)
            ->factory([FieldSelectionFactory::class, 'createEasels'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.panels_provider', InMemoryDataProvider::class)
            ->factory([FieldSelectionFactory::class, 'createPanels'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.curators_provider', InMemoryDataProvider::class)
            ->factory([FieldSelectionFactory::class, 'createCurators'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.panels_persister', InMemoryDataPersister::class)
            ->factory([FieldSelectionFactory::class, 'createPanelsPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.curators_persister', InMemoryDataPersister::class)
            ->factory([FieldSelectionFactory::class, 'createCuratorsPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
