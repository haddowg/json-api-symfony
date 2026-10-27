<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Scopes;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Tests\Functional\App\ArticleProviderFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\PinnedConfigDefaults;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\AuthorResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\CommentResource;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The in-memory OAuth scope kernel: articles, authors and comments, configured per
 * environment by {@see ScopesKernel}.
 */
final class ScopesInMemoryTestKernel extends Kernel
{
    use MicroKernelTrait;
    use ScopesKernel;

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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/scopes-in-memory-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/scopes-in-memory-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/scopes-in-memory-log';
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

        $services = $this->configureScopes($container);
        $services->set(ScopedArticleResource::class);
        $services->set($this->environment === 'public_scoped' ? PublicScopedAuthorResource::class : AuthorResource::class);
        $services->set($this->environment === 'unknown_relation' ? UnknownRelationScopedCommentResource::class : CommentResource::class);

        $services->set('test.authors_provider', InMemoryDataProvider::class)
            ->factory([ArticleProviderFactory::class, 'createAuthors'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.authors_persister', InMemoryDataPersister::class)
            ->factory([ArticleProviderFactory::class, 'authorsPersister'])
            ->args([service('test.authors_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
        $services->set('test.comments_provider', InMemoryDataProvider::class)
            ->factory([ArticleProviderFactory::class, 'createComments'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.comments_persister', InMemoryDataPersister::class)
            ->factory([ArticleProviderFactory::class, 'commentsPersister'])
            ->args([service('test.comments_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
        $services->set('test.articles_provider', InMemoryDataProvider::class)
            ->factory([ArticleProviderFactory::class, 'createWritableArticles'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.articles_persister', InMemoryDataPersister::class)
            ->factory([ArticleProviderFactory::class, 'articlesPersister'])
            ->args([service('test.articles_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $this->importRoutes($routes);
    }
}
