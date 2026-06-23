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
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The in-memory **relationship-mutation** kernel: all three types
 * (`articles`/`authors`/`comments`) as writable in-memory providers over one
 * shared, seeded object graph ({@see RelationshipMutationFactory}), plus an
 * {@see InMemoryDataPersister} for `articles` whose related-object resolver reads
 * the authors/comments stores — so the {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler}
 * can replace/add/remove a parent article's `author` / `comments` association and
 * a follow-up read sees the change.
 */
final class RelationshipMutationInMemoryTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/relmut-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/relmut-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/relmut-log';
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

        $services->set('test.articles_provider', InMemoryDataProvider::class)
            ->factory([RelationshipMutationFactory::class, 'createArticles'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.authors_provider', InMemoryDataProvider::class)
            ->factory([RelationshipMutationFactory::class, 'createAuthors'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.comments_provider', InMemoryDataProvider::class)
            ->factory([RelationshipMutationFactory::class, 'createComments'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.articles_persister', InMemoryDataPersister::class)
            ->factory([RelationshipMutationFactory::class, 'createArticlesPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.authors_persister', InMemoryDataPersister::class)
            ->factory([RelationshipMutationFactory::class, 'createAuthorsPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.comments_persister', InMemoryDataPersister::class)
            ->factory([RelationshipMutationFactory::class, 'createCommentsPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
