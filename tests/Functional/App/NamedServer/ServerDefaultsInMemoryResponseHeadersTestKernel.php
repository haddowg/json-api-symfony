<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Tests\Functional\App\Http\DeprecatedWidgetResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Http\HeaderWidgetFactory;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * {@see BothServersInMemoryResponseHeadersTestKernel} with {@see ServerHeaderDefaults} on
 * the named server, and a `deprecatedWidgets` there that declares its own sunset.
 */
final class ServerDefaultsInMemoryResponseHeadersTestKernel extends BothServersInMemoryResponseHeadersTestKernel
{
    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '-server-defaults';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        parent::configureContainer($container, $loader, $builder);
        ServerHeaderDefaults::apply($container);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(DeprecatedWidgetResource::class);
        $services->set('test.deprecated_widgets_provider', InMemoryDataProvider::class)
            ->factory([HeaderWidgetFactory::class, 'createDeprecatedProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.deprecated_widgets_persister', InMemoryDataPersister::class)
            ->factory([HeaderWidgetFactory::class, 'createDeprecatedPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }
}
