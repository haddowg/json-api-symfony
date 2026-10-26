<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FillUsing;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

final class FillUsingDoctrineTestKernel extends AbstractFillUsingKernel
{
    protected function dataBundles(): iterable
    {
        yield new DoctrineBundle();
    }

    protected function name(): string
    {
        return 'fill-using-doctrine';
    }

    protected function resources(): array
    {
        return [DoctrineFillAgencyResource::class, DoctrineFillMemberResource::class, DoctrineFillFileResource::class];
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $orm = [
            'auto_generate_proxy_classes' => true,
            'report_fields_where_declared' => true,
            'auto_mapping' => false,
            'mappings' => [
                'FillUsing' => [
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

        $services->set(HiddenFillRowsExtension::class);
    }
}
