<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdSource;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\IdSource\Doctrine\BadgeResource;
use haddowg\JsonApiBundle\Tests\Functional\App\IdSource\Doctrine\CounterResource;
use haddowg\JsonApiBundle\Tests\Functional\App\IdSource\Doctrine\MarkerResource;
use haddowg\JsonApiBundle\Tests\Functional\App\IdSource\Doctrine\SlugResource;
use haddowg\JsonApiBundle\Tests\Functional\App\IdSource\Doctrine\TokenResource;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The Id source/policy witness kernel (bundle ADR 0039): one Doctrine-sqlite kernel
 * registering a resource per id-sourcing behaviour the model expresses —
 * {@see CounterResource} (store-provided, the DB assigns), {@see MarkerResource}
 * (`allowClientId()` + `uuid()->generated()`), {@see BadgeResource} (`ulid()->generated()`),
 * {@see TokenResource} (`requireClientId()`) and {@see SlugResource} (`generateUsing()`),
 * with the Symfony Validator bridge wired so the linkage-format validation runs. Each is
 * served over the Doctrine `-128` fallback provider/persister from its
 * `#[AsJsonApiResource(entity: …)]` map alone — no per-type engine code.
 */
final class IdSourceTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/id-source-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/id-source-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/id-source-log';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $container->extension('framework', [
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
            'validation' => ['enabled' => true],
        ]);

        $orm = [
            'auto_generate_proxy_classes' => true,
            'report_fields_where_declared' => true,
            'auto_mapping' => false,
            'mappings' => [
                'IdSourceApp' => [
                    'type' => 'attribute',
                    'dir' => __DIR__ . '/Doctrine',
                    'prefix' => 'haddowg\JsonApiBundle\Tests\Functional\App\IdSource\Doctrine',
                    'is_bundle' => false,
                ],
            ],
        ];

        if (!\trait_exists(\Symfony\Component\VarExporter\LazyGhostTrait::class)) {
            $orm['enable_native_lazy_objects'] = true;
        }

        $container->extension('doctrine', [
            'dbal' => [
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ],
            'orm' => $orm,
        ]);

        $container->extension('json_api', [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(CounterResource::class);
        $services->set(MarkerResource::class);
        $services->set(BadgeResource::class);
        $services->set(TokenResource::class);
        $services->set(SlugResource::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
