<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Async;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\ArticleProviderFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\ArticleResource;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * A kernel whose `articles` writes are accepted asynchronously
 * ({@see AsyncArticlesPersister}) so `POST`/`PATCH` render a `202 Accepted` — the
 * async-write seam witness (bundle ADR 0110). The seeded {@see InMemoryDataProvider}
 * still serves reads (the async `PATCH` loads its target through it), a standalone
 * {@see JobSerializer} renders the `202` job body, and the collection-scope
 * {@see CompleteJobAction} drives the `303 See Other` completion leg.
 */
final class AsyncInMemoryTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/async-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/async-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/async-log';
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

        // The standalone job serializer (renders the 202 body) and the completion action.
        $services->set(JobSerializer::class);
        $services->set(CompleteJobAction::class);

        // Reads run over the seeded in-memory provider so an async PATCH can load its
        // target; writes route to the async persister below.
        $services->set('test.articles_provider', InMemoryDataProvider::class)
            ->factory([ArticleProviderFactory::class, 'createArticles'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set(AsyncArticlesPersister::class)
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
