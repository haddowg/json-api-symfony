<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Routing\OpenApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\PinnedConfigDefaults;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Zenstruck\Foundry\ZenstruckFoundryBundle;

/**
 * Serves `campaign-templates`, keyed by a OneToOne association to a campaign, and
 * `placeholder-definitions`, keyed by a binary UUID, joined through a pivot entity
 * whose own binary UUID id and collection come from its constructor.
 */
final class PivotKeysTestKernel extends Kernel
{
    use MicroKernelTrait;

    private const string NAME = 'pivot-keys';

    /**
     * Adds types sharing the definition entity: `named` a second type with its own
     * encoder, `shared` a second type with the same encoder and templates whose filter
     * names no definition type, and `clash` both of the clashing ones, which fails the
     * warm-up, and `pivot-clash` a pivot relation whose pivot-row filter clashes with a
     * related relation stored as `pivot`, which fails the warm-up too.
     */
    public static string $variant = '';

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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/' . self::NAME . '-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/' . self::NAME . '-cache/' . self::$variant . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/' . self::NAME . '-log';
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
        PinnedConfigDefaults::apply($container, $builder);

        $orm = [
            'auto_generate_proxy_classes' => true,
            'report_fields_where_declared' => true,
            'auto_mapping' => false,
            'mappings' => [
                'PivotKeysApp' => [
                    'type' => 'attribute',
                    'dir' => __DIR__,
                    'prefix' => 'haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys',
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
            'openapi' => ['expose_in_prod' => true],
            'atomic_operations' => ['enabled' => true],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(PkTemplateResource::class);
        $services->set(PkDefinitionResource::class);
        if (\in_array(self::$variant, ['named', 'clash'], true)) {
            $services->set(PkLabelledDefinitionResource::class);
        }
        if (self::$variant === 'shared') {
            $services->set(PkMirrorDefinitionResource::class);
        }
        if (\in_array(self::$variant, ['shared', 'clash'], true)) {
            $services->set(PkBareTemplateResource::class);
        }
        if (self::$variant === 'pivot-clash') {
            $services->set(PkClashTemplateResource::class);
            $services->set(PkClashDefinitionResource::class);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $routes->import('.', OpenApiRouteLoader::ROUTE_TYPE);
    }
}
