<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Zenstruck\Foundry\ZenstruckFoundryBundle;

/**
 * The Doctrine functional kernel: FrameworkBundle + DoctrineBundle +
 * JsonApiBundle over an in-memory SQLite database, with the
 * {@see DoctrineArticleResource} discovered by autoconfiguration so its
 * `#[AsJsonApiResource(entity: …)]` mapping routes `articles` to the
 * {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider}.
 * No in-memory provider is registered here — every request goes through the
 * Doctrine read path. The schema is created and seeded by the test
 * ({@see \haddowg\JsonApiBundle\Tests\Functional\DoctrineReadQueryTest}), since
 * the in-memory database lives and dies with the kernel's connection.
 */
final class DoctrineJsonApiTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new ZenstruckFoundryBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        // Point the project dir at the temp tree so FrameworkBundle's
        // auto-generated config lands there, not in the bundle.
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/doctrine-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/doctrine-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/doctrine-log';
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

        $orm = [
            'auto_generate_proxy_classes' => true,
            'report_fields_where_declared' => true,
            'auto_mapping' => false,
            'mappings' => [
                'JsonApiTestApp' => [
                    'type' => 'attribute',
                    'dir' => __DIR__,
                    'prefix' => 'haddowg\JsonApiBundle\Tests\Functional\App\Doctrine',
                    'is_bundle' => false,
                ],
            ],
        ];

        // Symfony 8 removed var-exporter's LazyGhostTrait, and Doctrine ORM
        // then requires PHP >= 8.4 native lazy objects for its proxies. Set
        // conditionally: the option only exists on DoctrineBundle versions new
        // enough to support that stack, while the lowest supported deps
        // (Symfony 6.4) still ship LazyGhost and predate the option.
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

        $services->set(DoctrineArticleResource::class);
        $services->set(DoctrineAuthorResource::class);
        $services->set(DoctrineCommentResource::class);

        // The genericity witness: a `tags` type served over the Doctrine path by
        // the `-128` fallback provider/persister from its `#[AsJsonApiResource]`
        // entity map alone — no per-type engine code (ADR 0021).
        $services->set(DoctrineTagResource::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
