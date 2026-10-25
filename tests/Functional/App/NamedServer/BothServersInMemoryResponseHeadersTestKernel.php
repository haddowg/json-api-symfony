<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Tests\Functional\App\Http\DefaultResponseHeadersTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Http\HeaderWidgetFactory;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * {@see DefaultResponseHeadersTestKernel} with its resources on a named server, another
 * `cachedWidgets` and `plainWidgets` on the default server, `taggedWidgets` served by a
 * different class on each server, and an untagged action on two of them.
 */
final class BothServersInMemoryResponseHeadersTestKernel extends DefaultResponseHeadersTestKernel
{
    use ServesOnBothServers {
        configureContainer as private configureBothServers;
    }

    protected function ownServerResources(): array
    {
        return [
            InMemoryDefaultServerCachedWidgetResource::class,
            InMemoryDefaultServerPlainWidgetResource::class,
            InMemoryDefaultServerTaggedWidgetResource::class,
            InMemoryNamedServerTaggedWidgetResource::class,
            PingTaggedWidget::class,
            PingPlainWidget::class,
        ];
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $this->configureBothServers($container, $loader, $builder);

        $services = $container->services();
        $services->set('test.tagged_widgets_provider', InMemoryDataProvider::class)
            ->factory([HeaderWidgetFactory::class, 'createTaggedProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.tagged_widgets_persister', InMemoryDataPersister::class)
            ->factory([HeaderWidgetFactory::class, 'createTaggedPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }
}
