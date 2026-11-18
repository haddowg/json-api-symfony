<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\DBAL\Logging\Middleware as DbalLoggingMiddleware;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\PinnedConfigDefaults;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The foreign-key witness kernel on Doctrine: `fk-users` and the `/inbox` singleton
 * served by {@see FkUserProvider}, their related rows on Doctrine, and `fk-teams`, an
 * entity reaching its members only through their foreign key. Its queries are counted.
 */
class ForeignKeyDoctrineTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        if ($this->doctrine()) {
            yield new DoctrineBundle();
        }
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/' . $this->name() . '-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/' . $this->name() . '-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/' . $this->name() . '-log';
    }

    protected function doctrine(): bool
    {
        return true;
    }

    protected function name(): string
    {
        return 'foreign-key-doctrine';
    }

    /**
     * Whether a windowed read runs as one `ROW_NUMBER` query, or one bounded query per
     * parent.
     */
    protected function windowFunctions(): bool
    {
        return true;
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

        $config = [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
            'atomic_operations' => ['enabled' => true],
        ];
        if ($this->doctrine()) {
            $config['doctrine'] = ['window_functions' => $this->windowFunctions()];
        }
        $container->extension('json_api', $config);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set('logger', \Psr\Log\NullLogger::class);
        $services->set(FkUserProvider::class);
        $services->set(FkUserPersister::class);
        $services->set(FkUserResource::class);
        $services->set(FkInboxResource::class);
        $services->set(FkImportResource::class);
        $services->set(ImportSecrets::class);

        $this->configureData($container, $services);
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $orm = [
            'auto_generate_proxy_classes' => true,
            'report_fields_where_declared' => true,
            'auto_mapping' => false,
            'mappings' => [
                'ForeignKey' => [
                    'type' => 'attribute',
                    'dir' => __DIR__,
                    'prefix' => __NAMESPACE__,
                    'is_bundle' => false,
                ],
            ],
        ];
        if (!\trait_exists(\Symfony\Component\VarExporter\LazyGhostTrait::class)) {
            $orm['enable_native_lazy_objects'] = true;
        }

        $container->extension('doctrine', [
            'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'orm' => $orm,
        ]);

        $services->set(QueryCountingLogger::class)->public();
        $services->set(DbalLoggingMiddleware::class)
            ->args([new Reference(QueryCountingLogger::class)])
            ->tag('doctrine.middleware');
        $services->set(FkSecretExtension::class);

        $services->set(DoctrineFkItemResource::class);
        $services->set(DoctrineFkNoteResource::class);
        $services->set(DoctrineFkProfileResource::class);
        $services->set(DoctrineFkSecretResource::class);
        $services->set(DoctrineFkTeamResource::class);
        $services->set(DoctrineFkMemberResource::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
