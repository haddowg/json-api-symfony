<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SecurityEnforcement;

use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\PinnedConfigDefaults;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Builds one `guard-gadgets` type per {@see $variant}, with or without a firewall
 * ({@see $firewall}), so the suite can show which security declarations fail the
 * build when nothing can evaluate them.
 *
 * Variants: `resource` (an expression on the resource attribute), `relation` (an
 * expression on a relation), `action` (an expression on a custom action),
 * `documented` (only `true`/`false`) and `none`.
 */
final class SecurityEnforcementKernel extends Kernel
{
    use MicroKernelTrait;

    public static string $variant = 'none';

    public static bool $firewall = false;

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        if (static::$firewall) {
            yield new SecurityBundle();
        }
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/security-enforcement-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/security-enforcement-cache/'
            . static::$variant . '-' . (static::$firewall ? 'firewall' : 'none') . '/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/security-enforcement-log';
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

        $container->extension('json_api', [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
        ]);

        if (static::$firewall) {
            $container->extension('security', [
                'firewalls' => ['main' => ['security' => false]],
            ]);
        }

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(match (static::$variant) {
            'resource' => ResourceExpressionGadgetResource::class,
            'relation' => RelationExpressionGadgetResource::class,
            'documented' => DocumentedGadgetResource::class,
            'catalog' => CatalogGadgetResource::class,
            default => PlainGadgetResource::class,
        });

        if (static::$variant === 'action' || static::$variant === 'catalog') {
            $services->set(InspectGadget::class);
        }

        $services->set('test.guard_gadgets_provider', \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider::class)
            ->factory([GuardGadgetDataFactory::class, 'provider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
