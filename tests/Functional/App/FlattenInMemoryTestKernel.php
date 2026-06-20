<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\InMemoryFlattenAuthorResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\InMemoryFlattenBookResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\InMemoryFlattenCountryResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\InMemoryFlattenPublisherResource;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The in-memory half of the flattened-attribute (`on()`) conformance pair (bundle
 * ADR 0085): the {@see InMemoryFlattenBookResource} (flattened single-hop `authorName`,
 * multi-hop `authorCountry` (`on('author.country')`), computed `display`) and its
 * related types {@see InMemoryFlattenAuthorResource} / {@see InMemoryFlattenCountryResource}
 * / {@see InMemoryFlattenPublisherResource} over a writable in-memory graph
 * ({@see FlattenProviderFactory}), with an {@see InMemoryDataPersister} for `books` so a
 * flattened-attribute PATCH mutates the shared related model and a follow-up read sees
 * it. Distinct cache/project dirs keep its compiled container from colliding with the
 * other kernels'.
 */
final class FlattenInMemoryTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/flatten-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/flatten-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/flatten-log';
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

        $services->set(InMemoryFlattenBookResource::class);
        $services->set(InMemoryFlattenAuthorResource::class);
        $services->set(InMemoryFlattenPublisherResource::class);
        $services->set(InMemoryFlattenCountryResource::class);

        $services->set('test.flatten_books_provider', InMemoryDataProvider::class)
            ->factory([FlattenProviderFactory::class, 'createBooks'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.flatten_authors_provider', InMemoryDataProvider::class)
            ->factory([FlattenProviderFactory::class, 'createAuthors'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.flatten_publishers_provider', InMemoryDataProvider::class)
            ->factory([FlattenProviderFactory::class, 'createPublishers'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.flatten_countries_provider', InMemoryDataProvider::class)
            ->factory([FlattenProviderFactory::class, 'createCountries'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.flatten_books_persister', InMemoryDataPersister::class)
            ->factory([FlattenProviderFactory::class, 'createBooksPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
