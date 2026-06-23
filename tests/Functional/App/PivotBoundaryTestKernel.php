<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\PivotPlaylistResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\PivotTrackResource;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The in-memory pivot boundary witness kernel: a `playlists` type whose `tracks`
 * relation is a {@see \haddowg\JsonApi\Resource\Field\BelongsToMany} declaring pivot
 * fields, served over the in-memory provider (which is NOT pivot-aware). It proves
 * the documented boundary — pivot is Doctrine-only: on this provider a pivot
 * `?filter`/`?sort` key is unrecognised (400) and no pivot meta renders, while the
 * track's own `?filter[title]` still works.
 */
final class PivotBoundaryTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/pivot-boundary-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/pivot-boundary-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/pivot-boundary-log';
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

        $services->set(PivotPlaylistResource::class);
        $services->set(PivotTrackResource::class);

        $services->set('test.pivot_playlists_provider', InMemoryDataProvider::class)
            ->factory([PivotPlaylistFactory::class, 'createProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.pivot_tracks_provider', InMemoryDataProvider::class)
            ->factory([PivotPlaylistFactory::class, 'createTracksProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.pivot_playlists_persister', InMemoryDataPersister::class)
            ->factory([PivotPlaylistFactory::class, 'createPersister'])
            ->args([service('test.pivot_playlists_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.pivot_tracks_persister', InMemoryDataPersister::class)
            ->factory([PivotPlaylistFactory::class, 'createTracksPersister'])
            ->args([service('test.pivot_tracks_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
