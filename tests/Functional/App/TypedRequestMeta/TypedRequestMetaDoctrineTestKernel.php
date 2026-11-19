<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\PinnedConfigDefaults;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Serves `tr-notes`, `tr-items` and `tr-plains` over Doctrine on sqlite, beside the
 * composed `tr-labels`, the always-accepted `tr-exports` and their `tr-jobs`.
 */
class TypedRequestMetaDoctrineTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
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
        return 'typed-request-meta-doctrine';
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

        // A note's update is gated by a security expression.
        $container->extension('security', [
            'firewalls' => ['main' => ['security' => false]],
        ]);

        $container->extension('json_api', [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
            'atomic_operations' => ['enabled' => true],
        ]);

        $builder->register('logger', \Psr\Log\NullLogger::class);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(TrLabelSerializer::class);
        $services->set(TrLabelsProvider::class);
        $services->set(TrJobSerializer::class);
        $services->set(TrExportResource::class);
        $services->set(TrExportsPersister::class)->tag(JsonApiBundle::DATA_PERSISTER_TAG);
        $services->set(TrMetaRecorder::class);
        $services->set(ArchiveNote::class);
        $services->set(ImportItem::class);
        $services->set(QueueNote::class);

        $this->configureData($container, $services);
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $services->set(DoctrineTrNoteResource::class);
        $services->set(DoctrineTrItemResource::class);
        $services->set(DoctrineTrPlainResource::class);

        $orm = [
            'auto_generate_proxy_classes' => true,
            'report_fields_where_declared' => true,
            'auto_mapping' => false,
            'mappings' => [
                'TypedRequestMeta' => [
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
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
