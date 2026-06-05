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

/**
 * The {@see DoctrineJsonApiTestKernel} setup plus two application providers
 * for the same `articles` type the {@see DoctrineArticleResource} maps to an
 * entity: {@see OverridingArticleProvider} by plain autoconfiguration (default
 * priority, must shadow the Doctrine fallback) and
 * {@see AboveFallbackArticleProvider} tagged between the default and the
 * fallback (must split them). Three providers claim the type and only the
 * tag-priority contract decides the order
 * ({@see \haddowg\JsonApiBundle\Tests\Functional\DataProviderPriorityTest}).
 * No schema is ever created and nothing is seeded: a read that reached the
 * Doctrine provider could not succeed.
 */
final class ProviderOverrideTestKernel extends Kernel
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
        // Point the project dir at the temp tree so FrameworkBundle's
        // auto-generated config lands there, not in the bundle.
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/override-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/override-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/override-log';
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

        // Autoconfiguration tags the provider at the default priority (0);
        // beating the Doctrine fallback (-128) must need nothing more.
        $services->set(OverridingArticleProvider::class);

        // Tagged *between* the default and the fallback — autoconfiguration
        // off so the explicit priority is its only tag. It sorts before the
        // Doctrine provider only because the bundle registers the fallback
        // below -64; a bare-tagged (priority 0) Doctrine provider would
        // outrank it.
        $services->set(AboveFallbackArticleProvider::class)
            ->autoconfigure(false)
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG, ['priority' => -64]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
