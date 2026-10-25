<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecurityTestKernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The in-memory per-server registry kernel: standalone `notes` relations, a standalone `labels` serializer per server, and `segmentWidgets` and `patternedWidgets` served by a different class on each server.
 */
final class BothServersInMemoryRegistriesTestKernel extends InMemorySecurityTestKernel
{
    use ServesOnBothServers {
        configureContainer as private configureBothServers;
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '-registries';
    }

    protected function ownServerResources(): array
    {
        return [
            NoteSerializer::class,
            DefaultServerLabelSerializer::class,
            NamedServerLabelSerializer::class,
            DefaultServerNoteRelations::class,
            NamedServerNoteRelations::class,
            PingSegmentWidget::class,
            InMemoryDefaultServerPatternedWidgetResource::class,
            InMemoryNamedServerPatternedWidgetResource::class,
            InMemoryDefaultServerSegmentWidgetResource::class,
            InMemoryNamedServerSegmentWidgetResource::class,
        ];
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $this->configureBothServers($container, $loader, $builder);

        $services = $container->services();
        $services->set('test.notes_provider', InMemoryDataProvider::class)
            ->factory([NamedServerProviders::class, 'notes'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.labels_provider', InMemoryDataProvider::class)
            ->factory([NamedServerProviders::class, 'labels'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.segment_widgets_provider', InMemoryDataProvider::class)
            ->factory([NamedServerProviders::class, 'segmentWidgets'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.patterned_widgets_provider', InMemoryDataProvider::class)
            ->factory([NamedServerProviders::class, 'patternedWidgets'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
    }
}
