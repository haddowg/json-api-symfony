<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\ArticleResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\TagResource;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The in-memory **write** kernel: the same shape as {@see JsonApiTestKernel}, but
 * it registers a writable {@see InMemoryDataProvider} (an identifier closure makes
 * its store writable) and an {@see InMemoryDataPersister} over that same store, so
 * the {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler} can create,
 * update, and delete `articles` and a follow-up read sees the change.
 */
final class WritableInMemoryTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/writable-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/writable-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/writable-log';
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

        $services->set('test.articles_provider', InMemoryDataProvider::class)
            ->factory([WritableArticleFactory::class, 'createProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        // The persister shares the provider's store, so writes are readable.
        $services->set('test.articles_persister', InMemoryDataPersister::class)
            ->factory([WritableArticleFactory::class, 'createPersister'])
            ->args([service('test.articles_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        // The genericity witness: a `tags` type added with nothing but its
        // resource + POJO + the same in-memory provider/persister wiring shape —
        // no per-type engine code (ADR 0021).
        $services->set(TagResource::class);

        $services->set('test.tags_provider', InMemoryDataProvider::class)
            ->factory([WritableTagFactory::class, 'createProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.tags_persister', InMemoryDataPersister::class)
            ->factory([WritableTagFactory::class, 'createPersister'])
            ->args([service('test.tags_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
