<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

class CmDoctrineTestKernel extends AbstractCmKernel
{
    protected function dataBundles(): iterable
    {
        yield new DoctrineBundle();
    }

    protected function name(): string
    {
        return 'computed-morph-doctrine';
    }

    protected function scoped(): bool
    {
        return false;
    }

    protected function entities(): array
    {
        return [
            CmAgencyResource::class => CmAgency::class,
            CmAdvertiserResource::class => CmAdvertiser::class,
            CmPublisherResource::class => CmPublisher::class,
            CmUserResource::class => CmUser::class,
        ];
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $orm = [
            'auto_generate_proxy_classes' => true,
            'report_fields_where_declared' => true,
            'auto_mapping' => false,
            'mappings' => [
                'ComputedMorph' => [
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

        $services->set(CmCompanyLookup::class, DoctrineCmCompanyLookup::class);
        if ($this->scoped()) {
            $services->set(HiddenAgenciesExtension::class);
        }
    }
}
