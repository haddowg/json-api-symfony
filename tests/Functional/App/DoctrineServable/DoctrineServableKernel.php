<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DoctrineServable;

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

/**
 * The Doctrine servability warm-up guard fixture kernel (guards A3 + A7): a
 * Doctrine-configured app that registers one toggled `widgets` resource (named via
 * {@see $subjectResource}) mapped to {@see GuardWidgetEntity}, plus the far
 * {@see GuardGadgetResource} mapped to {@see GuardGadgetEntity}, served by the
 * bundle's `-128` fallback Doctrine provider/persister.
 *
 * Booting a cold-cache kernel runs `cache:warmup` and so the non-optional
 * {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineServableWarmer}: a
 * misconfigured subject (a computed() sortable field, a filter on a non-column, or an
 * unresolvable pivot) throws a `\LogicException` from `bootKernel()` itself; a legit
 * subject boots clean. The cache dir is keyed by the subject class so each scenario
 * compiles its own container.
 */
final class DoctrineServableKernel extends Kernel
{
    use MicroKernelTrait;

    /** @var class-string */
    public static string $subjectResource = SafeWidgetResource::class;

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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/doctrine-servable-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/doctrine-servable-cache/'
            . $this->subjectKey() . '/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/doctrine-servable-log';
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
                'DoctrineServableApp' => [
                    'type' => 'attribute',
                    'dir' => __DIR__,
                    'prefix' => 'haddowg\JsonApiBundle\Tests\Functional\App\DoctrineServable',
                    'is_bundle' => false,
                ],
            ],
        ];

        // Symfony 8 removed var-exporter's LazyGhostTrait, and Doctrine ORM then
        // requires PHP >= 8.4 native lazy objects for its proxies; set conditionally
        // (the lowest supported deps, Symfony 6.4, still ship LazyGhost).
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

        $services->set(static::$subjectResource);
        $services->set(GuardGadgetResource::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }

    private function subjectKey(): string
    {
        $parts = \explode('\\', static::$subjectResource);

        return \end($parts);
    }
}
