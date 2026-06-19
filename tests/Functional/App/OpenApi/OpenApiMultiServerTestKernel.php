<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi;

use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Routing\OpenApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\MultiServer\AdminItemResource;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\MultiServer\PublicItemResource;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The multi-server OpenAPI witness (design D5): a `default` server (a `public-items`
 * resource) and a named `admin` server (an `admin-items` resource). With
 * `multi_server: per_server` (the default), `GET /docs.json` serves the default
 * server's document and `GET /admin/docs.json` the admin server's — each scoped to its
 * own server's types and base URI.
 *
 * The `$combined` constructor flag flips `multi_server: combined` so the suite can
 * assert the combined-mode single-document behaviour from the same fixtures.
 */
final class OpenApiMultiServerTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(string $environment, bool $debug, private readonly bool $combined = false)
    {
        parent::__construct($environment, $debug);
    }

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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/openapi-multi-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/openapi-multi-cache/' . ($this->combined ? 'combined-' : '') . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/openapi-multi-log';
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
            'base_uri' => 'https://public.test',
            'version' => '1.1',
            'servers' => [
                'admin' => ['base_uri' => 'https://admin.test'],
            ],
            'openapi' => [
                'expose_in_prod' => true,
                'multi_server' => $this->combined ? 'combined' : 'per_server',
            ],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set('logger', \Psr\Log\NullLogger::class);
        $services->set(PublicItemResource::class);
        $services->set(AdminItemResource::class);

        $services->set('test.openapi.public_items', \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider::class)
            ->factory([MultiServer\MultiServerItemFactory::class, 'publicItems'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.openapi.admin_items', \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider::class)
            ->factory([MultiServer\MultiServerItemFactory::class, 'adminItems'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $routes->import('admin', JsonApiRouteLoader::ROUTE_TYPE)->prefix('/admin');
        $routes->import('.', OpenApiRouteLoader::ROUTE_TYPE);
    }
}
