<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BoardResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\ImageResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\NoteResource;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The polymorphic witness kernel: a minimal Symfony app that declares BOTH a
 * polymorphic to-one (`pinned`, a {@see \haddowg\JsonApi\Resource\Field\MorphTo})
 * and a polymorphic to-many (`items`, a {@see \haddowg\JsonApi\Resource\Field\MorphToMany})
 * over two object-aware member types ({@see NoteResource}/{@see ImageResource}),
 * served by in-memory providers from {@see PolymorphicBoardFactory}. It registers
 * FrameworkBundle + the JsonApiBundle and imports the `jsonapi` route type so the
 * related / relationship endpoints exist. Cache and log dirs live under their own
 * temp tree so the witness does not share a compiled container with the other
 * kernels.
 */
final class PolymorphicRelationTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/polymorphic-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/polymorphic-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/polymorphic-log';
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

        $services->set(BoardResource::class);
        $services->set(NoteResource::class);
        $services->set(ImageResource::class);

        $services->set('test.boards_provider', InMemoryDataProvider::class)
            ->factory([PolymorphicBoardFactory::class, 'createBoards'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.notes_provider', InMemoryDataProvider::class)
            ->factory([PolymorphicBoardFactory::class, 'createNotes'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.images_provider', InMemoryDataProvider::class)
            ->factory([PolymorphicBoardFactory::class, 'createImages'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.boards_persister', InMemoryDataPersister::class)
            ->factory([PolymorphicBoardFactory::class, 'createBoardsPersister'])
            ->args([service('test.boards_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.notes_persister', InMemoryDataPersister::class)
            ->factory([PolymorphicBoardFactory::class, 'createNotesPersister'])
            ->args([service('test.notes_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.images_persister', InMemoryDataPersister::class)
            ->factory([PolymorphicBoardFactory::class, 'createImagesPersister'])
            ->args([service('test.images_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
