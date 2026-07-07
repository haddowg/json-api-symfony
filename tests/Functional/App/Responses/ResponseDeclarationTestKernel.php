<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Responses;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Routing\OpenApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Async\JobSerializer;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The per-operation response-declaration witness kernel: a {@see WidgetResource}
 * declaring every new response kind (async `202` create, `204` update, `200`
 * meta-only delete, `303` fetch-one completion), its in-memory provider/persister,
 * and the standalone {@see JobSerializer} (so the `jobs` document schema the `202`
 * references is emitted). OpenAPI serving is enabled so `GET /docs.json` exposes the
 * generated document for the projection assertions.
 */
final class ResponseDeclarationTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/responses-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/responses-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/responses-log';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
        ]);

        $container->extension('json_api', [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
            'openapi' => ['expose_in_prod' => true],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set('logger', NullLogger::class);

        $services->set(WidgetResource::class);
        // The async-action witness: a collection GET action declaring responds: [202, 303].
        $services->set(PollWidgetJob::class);
        // The standalone jobs serializer emits the `jobs` document schema the 202 refs.
        $services->set(JobSerializer::class);

        $services->set('test.widgets_provider', InMemoryDataProvider::class)
            ->factory([WidgetProviderFactory::class, 'widgets'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.widgets_persister', InMemoryDataPersister::class)
            ->factory([WidgetProviderFactory::class, 'widgetsPersister'])
            ->args([service('test.widgets_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $routes->import('.', OpenApiRouteLoader::ROUTE_TYPE);
    }
}
