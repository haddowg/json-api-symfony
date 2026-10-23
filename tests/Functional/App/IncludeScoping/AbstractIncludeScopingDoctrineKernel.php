<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Doctrine kernel for the include-scoping suite: the advertiser/agency/member types
 * on the `default` server and again (prefixed `partner-`) on the named `partner`
 * server only. Each concrete kernel hides the soft-hidden rows its own way.
 */
abstract class AbstractIncludeScopingDoctrineKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/include-scoping-' . $this->name() . '-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/include-scoping-' . $this->name() . '-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/include-scoping-' . $this->name() . '-log';
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
                'IncludeScoping' => [
                    'type' => 'attribute',
                    'dir' => __DIR__,
                    'prefix' => __NAMESPACE__,
                    'is_bundle' => false,
                ],
            ],
        ];

        // Symfony 8 dropped LazyGhostTrait; Doctrine then needs native lazy objects.
        if (!\trait_exists(\Symfony\Component\VarExporter\LazyGhostTrait::class)) {
            $orm['enable_native_lazy_objects'] = true;
        }

        $container->extension('doctrine', [
            'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'orm' => $orm,
        ]);

        $container->extension('json_api', [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
            'servers' => [
                'partner' => ['base_uri' => 'https://partner.test'],
            ],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        // The refused reads log an error; keep it off the test output.
        $builder->register('logger', \Psr\Log\NullLogger::class);

        $services->set($this->advertiserResources()[0]);
        $services->set(DefaultScopingAgencyResource::class);
        $services->set(DefaultScopingMemberResource::class);
        $services->set($this->advertiserResources()[1]);
        $services->set(PartnerScopingAgencyResource::class);
        $services->set(PartnerScopingMemberResource::class);
        $this->scope($services);

        $services->alias('test.scoping_include_batcher', \haddowg\JsonApiBundle\DataProvider\RelatedIncludeBatcher::class)->public();
    }

    abstract protected function name(): string;

    /**
     * The default-server and partner-server advertiser resources.
     *
     * @return array{class-string, class-string}
     */
    protected function advertiserResources(): array
    {
        return [DefaultScopingAdvertiserResource::class, PartnerScopingAdvertiserResource::class];
    }

    abstract protected function scope(DefaultsConfigurator $services): void;

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $routes->import('partner', JsonApiRouteLoader::ROUTE_TYPE)->prefix('/partner');
    }
}
