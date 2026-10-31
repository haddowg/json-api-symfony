<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\DBAL\Logging\Middleware as DbalLoggingMiddleware;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class MtmDoctrineTestKernel extends AbstractMtmKernel
{
    protected function dataBundles(): iterable
    {
        yield new DoctrineBundle();
    }

    protected function name(): string
    {
        return 'identified-morph-many-doctrine';
    }

    protected function scoped(): bool
    {
        return false;
    }

    protected function entities(): array
    {
        return [
            MtmAgencyResource::class => MtmAgency::class,
            MtmAdvertiserResource::class => MtmAdvertiser::class,
            MtmPublisherResource::class => MtmPublisher::class,
            MtmUserResource::class => MtmUser::class,
        ];
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $orm = [
            'auto_generate_proxy_classes' => true,
            'report_fields_where_declared' => true,
            'auto_mapping' => false,
            'mappings' => [
                'IdentifiedMorphMany' => [
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
            ->args([service(QueryCountingLogger::class)])
            ->tag('doctrine.middleware');

        if ($this->scoped()) {
            $services->set(HiddenAgenciesExtension::class);
        }
    }
}
