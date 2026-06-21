<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Atomic;

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
 * A multi-persister atomic kernel whose `articles` persister is wrapped in a
 * {@see FailingCommitPersister} (its `commit()` always throws), while `authors` and
 * `comments` commit normally — so a batch touching both `authors` and `articles`
 * resolves to two DISTINCT transactional persisters and forces the second commit to
 * fail.
 *
 * It is the witness for the executor's multi-persister commit boundary (finding 3):
 * the all-or-nothing guarantee is scoped to a single transactional persister per
 * batch; with two, the already-committed one's writes stand and only the
 * not-yet-committed ones roll back. Each type's persister wraps a SEPARATE in-memory
 * store (no shared cross-store coordinator), so the per-store commits are genuinely
 * independent.
 */
final class AtomicFailingCommitTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/atomic-failingcommit-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/atomic-failingcommit-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/atomic-failingcommit-log';
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
            'atomic_operations' => ['enabled' => true],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(ArticleResource::class);
        $services->set(AuthorResource::class);
        $services->set(CommentResource::class);

        $services->set('test.articles_provider', InMemoryDataProvider::class)
            ->factory([AtomicFailingCommitFactory::class, 'createArticles'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.authors_provider', InMemoryDataProvider::class)
            ->factory([AtomicFailingCommitFactory::class, 'createAuthors'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.comments_provider', InMemoryDataProvider::class)
            ->factory([AtomicFailingCommitFactory::class, 'createComments'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        // The `articles` persister fails on commit; `authors`/`comments` commit normally.
        $services->set('test.articles_persister', FailingCommitPersister::class)
            ->factory([AtomicFailingCommitFactory::class, 'createArticlesPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.authors_persister', InMemoryDataPersister::class)
            ->factory([AtomicFailingCommitFactory::class, 'createAuthorsPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.comments_persister', InMemoryDataPersister::class)
            ->factory([AtomicFailingCommitFactory::class, 'createCommentsPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
