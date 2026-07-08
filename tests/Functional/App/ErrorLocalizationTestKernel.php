<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\ArticleResource;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The error-localization witness kernel: the writable in-memory `articles` shape (a
 * constrained resource, so a create can 422), but configured for a **non-default
 * locale** (`fr`) with a `jsonapi_errors.fr.yaml` catalogue. So a real HTTP request
 * that trips a core catalogue error, or a validation `422`, renders through the
 * bundle's {@see \haddowg\JsonApiBundle\Server\TranslatorErrorMessageResolver}
 * (bundle ADR 0115) in French — the end-to-end witness of the resolver core's own
 * suite proves in isolation.
 */
final class ErrorLocalizationTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/error-l10n-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/error-l10n-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/error-l10n-log';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $container->extension('framework', [
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
            // A non-default locale + a jsonapi_errors.fr.yaml catalogue: the bundle's
            // TranslatorErrorMessageResolver looks up each error's title/detail in the
            // request's (fr) locale, and core interpolates the error context into it.
            'default_locale' => 'fr',
            'translator' => [
                'enabled' => true,
                'default_path' => __DIR__ . '/translations',
                'fallbacks' => ['en'],
            ],
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
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
