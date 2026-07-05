<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\ArticleResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\AuthorResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\CommentResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\CursorShelfResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\CursorWidgetResource;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * A minimal Symfony test kernel: it registers FrameworkBundle + the JsonApiBundle,
 * wires the `articles` fixtures (the {@see ArticleResource} service and an
 * {@see InMemoryDataProvider} seeded with a couple of {@see Article}s), supplies
 * the bundle config, and imports the `jsonapi` route type so `GET /articles` and
 * `GET /articles/{id}` exist — exercising the {@see JsonApiRouteLoader}
 * end-to-end. Cache and log dirs live under the system temp dir.
 */
final class JsonApiTestKernel extends Kernel
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
        // Point the project dir at the temp tree so FrameworkBundle's
        // auto-generated config/reference.php lands there, not in the bundle.
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/log';
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

        $services->set(ArticleResource::class);
        $services->set(AuthorResource::class);
        $services->set(CommentResource::class);

        // The cursor (keyset) conformance witness: a `cursorWidgets` type whose
        // pagination() returns a CursorPaginator, served over the in-memory provider
        // (bundle ADR 0063).
        $services->set(CursorWidgetResource::class);
        $services->set('test.cursor_widgets_provider', InMemoryDataProvider::class)
            ->factory([CursorWidgetProviderFactory::class, 'create'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.cursor_widgets_persister', InMemoryDataPersister::class)
            ->factory([CursorWidgetProviderFactory::class, 'createPersister'])
            ->args([service('test.cursor_widgets_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        // The RELATED-collection cursor (keyset) conformance witness: a
        // `cursorShelves` parent whose to-many `widgets` relation declares its own
        // CursorPaginator, its members drawn live from the `cursorWidgets` store
        // (bundle ADR 0063).
        $services->set(CursorShelfResource::class);
        $services->set('test.cursor_shelves_provider', InMemoryDataProvider::class)
            ->factory([CursorShelfProviderFactory::class, 'create'])
            ->args([service('test.cursor_widgets_provider')])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.cursor_shelves_persister', InMemoryDataPersister::class)
            ->factory([CursorShelfProviderFactory::class, 'createPersister'])
            ->args([service('test.cursor_shelves_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.articles_provider', InMemoryDataProvider::class)
            ->factory([ArticleProviderFactory::class, 'createArticles'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.authors_provider', InMemoryDataProvider::class)
            ->factory([ArticleProviderFactory::class, 'createAuthors'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.comments_provider', InMemoryDataProvider::class)
            ->factory([ArticleProviderFactory::class, 'createComments'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.articles_persister', InMemoryDataPersister::class)
            ->factory([ArticleProviderFactory::class, 'articlesPersister'])
            ->args([service('test.articles_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.authors_persister', InMemoryDataPersister::class)
            ->factory([ArticleProviderFactory::class, 'authorsPersister'])
            ->args([service('test.authors_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.comments_persister', InMemoryDataPersister::class)
            ->factory([ArticleProviderFactory::class, 'commentsPersister'])
            ->args([service('test.comments_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
