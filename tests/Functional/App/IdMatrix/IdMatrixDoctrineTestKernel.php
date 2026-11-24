<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

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
use Zenstruck\Foundry\ZenstruckFoundryBundle;

/**
 * Serves an owner type per id kind, posts and videos with colliding integer ids, and the
 * notes pointing back at all of them, from Doctrine on sqlite, which keeps a `uuid` or
 * `ulid` as 16 bytes. `ID_MATRIX_DB_URL` points it at another database.
 */
class IdMatrixDoctrineTestKernel extends Kernel
{
    use MicroKernelTrait;

    private const array RESOURCES = [
        'ImCodeOwnerResource', 'ImEncOwnerResource', 'ImGuidOwnerResource', 'ImIntOwnerResource', 'ImNoteResource', 'ImPostResource', 'ImPrefixOwnerResource', 'ImStrOwnerResource', 'ImUlidOwnerResource', 'ImUuidOwnerResource', 'ImVideoResource',
    ];

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        if ($this->doctrine()) {
            yield new DoctrineBundle();
        }
        yield new ZenstruckFoundryBundle();
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

    /**
     * @return list<class-string>
     */
    protected function resources(): array
    {
        /** @var list<class-string> $resources */
        $resources = \array_map(fn(string $resource): string => __NAMESPACE__ . '\\' . $this->resourcePrefix() . $resource, self::RESOURCES);

        return $resources;
    }

    protected function resourcePrefix(): string
    {
        return 'Doctrine';
    }

    protected function name(): string
    {
        return 'id-matrix-doctrine';
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
        $container->extension('json_api', ['base_uri' => 'https://example.test', 'version' => '1.1']);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set('logger', \Psr\Log\NullLogger::class);
        foreach ($this->resources() as $resource) {
            $services->set($resource);
        }

        $this->configureData($container, $services);
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $orm = [
            'auto_generate_proxy_classes' => true,
            'report_fields_where_declared' => true,
            'auto_mapping' => false,
            'mappings' => [
                'IdMatrix' => ['type' => 'attribute', 'dir' => __DIR__, 'prefix' => __NAMESPACE__, 'is_bundle' => false],
            ],
        ];
        if (!\trait_exists(\Symfony\Component\VarExporter\LazyGhostTrait::class)) {
            $orm['enable_native_lazy_objects'] = true;
        }

        $url = \getenv('ID_MATRIX_DB_URL');
        $dbal = \is_string($url) && $url !== '' ? ['url' => $url] : ['driver' => 'pdo_sqlite', 'memory' => true];
        $dbal['types'] = [CodeType::NAME => CodeType::class];
        $container->extension('doctrine', ['dbal' => $dbal, 'orm' => $orm]);
        $services->set(ImHiddenRowsExtension::class);
        // The executed statements, so a test can tell a windowed read from one per parent.
        $services->set(QueryCountingLogger::class)->public();
        $services->set(DbalLoggingMiddleware::class)
            ->args([new Reference(QueryCountingLogger::class)])
            ->tag('doctrine.middleware');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
