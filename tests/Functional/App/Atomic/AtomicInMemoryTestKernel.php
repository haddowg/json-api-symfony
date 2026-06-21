<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Atomic;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\ArticleResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\AuthorResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\TagResource;
use haddowg\JsonApiBundle\Tests\Functional\App\WritableTagFactory;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The in-memory **Atomic Operations** kernel: the `articles`/`authors`/`comments`
 * relationship graph (writable, over ONE shared {@see \haddowg\JsonApiBundle\DataProvider\InMemorySnapshotCoordinator}
 * so an atomic rollback restores cross-store object identity) PLUS a `tags` type
 * whose persister is deliberately NON-transactional ({@see NonTransactionalTagPersister})
 * — the pre-flight-refusal witness. `atomic_operations` is enabled, so the kernel
 * serves `POST /operations`.
 *
 * The `comments` resource is the {@see ThrowingAfterCreateCommentResource}: its
 * post-commit `afterCreate` hook throws for the sentinel `BOOM` body, the witness that
 * a committed batch is NOT failed by a throwing After* hook (Slice D, bundle ADR 0088).
 */
final class AtomicInMemoryTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/atomic-inmemory-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/atomic-inmemory-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/atomic-inmemory-log';
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

        // The throwing post-commit-hook witness deliberately logs a hook exception
        // through the `logger` service (best-effort; the batch still succeeds), which by
        // default writes to stdout via error_log. Pre-register a NullLogger so Symfony's
        // LoggerPass stands down and the deliberate error does not leak into test output.
        $builder->register('logger', \Psr\Log\NullLogger::class);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(ArticleResource::class);
        $services->set(AuthorResource::class);
        // The `comments` resource here carries a throwing post-commit `afterCreate` hook
        // (gated on the BOOM sentinel body) — the witness that a committed batch is not
        // failed by a throwing After* hook (Slice D, bundle ADR 0088).
        $services->set(ThrowingAfterCreateCommentResource::class);
        $services->set(TagResource::class);

        $services->set('test.articles_provider', InMemoryDataProvider::class)
            ->factory([AtomicInMemoryFactory::class, 'createArticles'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.authors_provider', InMemoryDataProvider::class)
            ->factory([AtomicInMemoryFactory::class, 'createAuthors'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.comments_provider', InMemoryDataProvider::class)
            ->factory([AtomicInMemoryFactory::class, 'createComments'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.articles_persister', InMemoryDataPersister::class)
            ->factory([AtomicInMemoryFactory::class, 'createArticlesPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.authors_persister', InMemoryDataPersister::class)
            ->factory([AtomicInMemoryFactory::class, 'createAuthorsPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.comments_persister', InMemoryDataPersister::class)
            ->factory([AtomicInMemoryFactory::class, 'createCommentsPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        // The non-transactional pre-flight witness: `tags` reads from a normal
        // in-memory provider but writes through a persister that does NOT implement
        // TransactionalDataPersisterInterface, so a batch touching `tags` is refused
        // before any write.
        $services->set('test.tags_provider', InMemoryDataProvider::class)
            ->factory([WritableTagFactory::class, 'createProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.tags_persister', NonTransactionalTagPersister::class)
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
