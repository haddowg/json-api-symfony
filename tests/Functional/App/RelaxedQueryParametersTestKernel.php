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

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The same in-memory `articles` app as {@see JsonApiTestKernel}, but with
 * `json_api.strict_query_parameters` switched off — so an unrecognized top-level
 * query-parameter family is silently ignored (the pre-strict behaviour) rather
 * than rejected with a `400`. Exercises the relax toggle end to end (bundle ADR
 * 0055).
 */
final class RelaxedQueryParametersTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/relaxed-query-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/relaxed-query-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/relaxed-query-log';
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
            'strict_query_parameters' => false,
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(ArticleResource::class);
        $services->set(AuthorResource::class);
        $services->set(CommentResource::class);

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
