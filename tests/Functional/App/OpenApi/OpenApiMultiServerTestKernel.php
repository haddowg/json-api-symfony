<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi;

use haddowg\JsonApiBundle\Controller\OpenApiController;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Routing\OpenApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\MultiServer\AdminItemResource;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\MultiServer\PublicItemResource;
use haddowg\JsonApiBundle\Tests\Functional\App\PinnedConfigDefaults;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The multi-server OpenAPI witness (design D5): a `default` server (a `public-items`
 * resource) and a named `admin` server (an `admin-items` resource). With
 * `multi_server: per_server` (the default), `GET /docs.json` serves the default
 * server's document and `GET /admin/docs.json` the admin server's — each scoped to its
 * own server's types and base URI.
 *
 * The `$combined` constructor flag flips `multi_server: combined` so the suite can
 * assert the combined-mode single-document behaviour from the same fixtures. The
 * `$atomic` flag enables the (global) Atomic Operations extension so the suite can
 * assert each server's document gains the atomic `POST /operations` endpoint.
 *
 * `$docsRoutes` picks who serves the documents: `bundle` imports the bundle's routes,
 * `custom` routes {@see OpenApiController} under the app's own names and paths
 * (`/api/docs.json`, and `/api/v5/docs.json` for `admin`), and `none` serves them nowhere.
 * `$exposed` sets `expose_in_prod` and `$documentUrl` sets `document_url`. With
 * `$mountDefault` off only the `admin` server's routes are imported. The logger
 * keeps warnings in {@see Serve\WarningLogger}.
 */
final class OpenApiMultiServerTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(
        string $environment,
        bool $debug,
        private readonly bool $combined = false,
        private readonly bool $atomic = false,
        private readonly string $docsRoutes = 'bundle',
        private readonly bool $exposed = true,
        private readonly ?string $documentUrl = null,
        private readonly bool $mountDefault = true,
    ) {
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
        return \sys_get_temp_dir() . '/json-api-symfony-tests/openapi-multi-cache/' . ($this->combined ? 'combined-' : '') . ($this->atomic ? 'atomic-' : '')
            . ($this->docsRoutes !== 'bundle' ? $this->docsRoutes . '-' : '') . ($this->exposed ? '' : 'hidden-')
            . ($this->documentUrl !== null ? 'url-' : '') . ($this->mountDefault ? '' : 'unmounted-') . $this->environment;
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
        PinnedConfigDefaults::apply($container, $builder);

        $jsonApi = [
            'base_uri' => 'https://public.test',
            'version' => '1.1',
            'servers' => [
                'admin' => ['base_uri' => 'https://admin.test'],
            ],
            'openapi' => [
                'expose_in_prod' => $this->exposed,
                'multi_server' => $this->combined ? 'combined' : 'per_server',
                'document_url' => $this->documentUrl,
            ],
        ];

        // The Atomic Operations extension is a single GLOBAL flag, but the endpoint
        // (and therefore the OpenAPI path/components) exists per server.
        if ($this->atomic) {
            $jsonApi['atomic_operations'] = ['enabled' => true];
        }

        $container->extension('json_api', $jsonApi);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set('logger', Serve\WarningLogger::class);
        $services->set(PublicItemResource::class);
        $services->set(AdminItemResource::class);

        $services->set('test.openapi.public_items', \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider::class)
            ->factory([MultiServer\MultiServerItemFactory::class, 'publicItems'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.openapi.admin_items', \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider::class)
            ->factory([MultiServer\MultiServerItemFactory::class, 'adminItems'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.openapi.public_items_persister', \haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister::class)
            ->factory([MultiServer\MultiServerItemFactory::class, 'persister'])
            ->args(['public-items', service('test.openapi.public_items')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.openapi.admin_items_persister', \haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister::class)
            ->factory([MultiServer\MultiServerItemFactory::class, 'persister'])
            ->args(['admin-items', service('test.openapi.admin_items')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        if ($this->mountDefault) {
            $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        }
        $routes->import('admin', JsonApiRouteLoader::ROUTE_TYPE)->prefix('/admin');

        if ($this->docsRoutes === 'bundle') {
            $routes->import('.', OpenApiRouteLoader::ROUTE_TYPE);
        }

        if ($this->docsRoutes === 'custom') {
            $routes->add('app_docs', '/api/docs.json')
                ->controller(OpenApiController::class . '::__invoke')
                ->methods(['GET']);

            if (!$this->combined) {
                $routes->add('app_admin_docs', '/api/v5/docs.json')
                    ->controller(OpenApiController::class)
                    ->defaults(['server' => 'admin'])
                    ->methods(['GET']);
            }
        }
    }
}
