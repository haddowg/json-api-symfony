<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Include;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Include\Resource\CapResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Include\Resource\NodeResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Include\Resource\RootResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Include\Resource\TagResource;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The in-memory include-safeguards kernel (bundle ADR 0037). It leaves
 * `json_api.max_include_depth` UNSET so the bundle's opinionated default of `3` is
 * in force, and registers the circular `nodes` chain plus the `tags`/`roots`/`caps`
 * witnesses so the {@see \haddowg\JsonApiBundle\Tests\Functional\IncludeSafeguardsConformanceTestCase}
 * can prove, on the in-memory provider: a `cannotBeIncluded()` relation `400`s
 * (Capability A); a too-deep `?include` `400`s and a mutual default-include cycle
 * terminates at the cap (Capability B); a per-resource depth override wins; and a
 * root allowed-include-paths whitelist forbids a nested path that is includable from
 * its own root (Capability C).
 */
final class IncludeSafeguardsTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/include-safeguards-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/include-safeguards-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/include-safeguards-log';
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

        // No max_include_depth key: the bundle default of 3 governs.
        $container->extension('json_api', [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(NodeResource::class);
        $services->set(TagResource::class);
        $services->set(RootResource::class);
        $services->set(CapResource::class);

        $services->set('test.nodes_provider', InMemoryDataProvider::class)
            ->factory([IncludeProviderFactory::class, 'createNodes'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.tags_provider', InMemoryDataProvider::class)
            ->factory([IncludeProviderFactory::class, 'createTags'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.roots_provider', InMemoryDataProvider::class)
            ->factory([IncludeProviderFactory::class, 'createRoots'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.caps_provider', InMemoryDataProvider::class)
            ->factory([IncludeProviderFactory::class, 'createCaps'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.nodes_persister', InMemoryDataPersister::class)
            ->factory([IncludeProviderFactory::class, 'createNodesPersister'])
            ->args([service('test.nodes_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.tags_persister', InMemoryDataPersister::class)
            ->factory([IncludeProviderFactory::class, 'createTagsPersister'])
            ->args([service('test.tags_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.roots_persister', InMemoryDataPersister::class)
            ->factory([IncludeProviderFactory::class, 'createRootsPersister'])
            ->args([service('test.roots_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.caps_persister', InMemoryDataPersister::class)
            ->factory([IncludeProviderFactory::class, 'createCapsPersister'])
            ->args([service('test.caps_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
