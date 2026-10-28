<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * {@see BothServersResponseHeadersTestKernel} with {@see ServerHeaderDefaults} on the
 * named server, and a `deprecatedWidgets` there that declares its own sunset.
 */
final class ServerDefaultsResponseHeadersTestKernel extends BothServersResponseHeadersTestKernel
{
    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '-server-defaults';
    }

    protected function ownServerResources(): array
    {
        return [...parent::ownServerResources(), NamedServerDeprecatedWidgetResource::class];
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        parent::configureContainer($container, $loader, $builder);
        ServerHeaderDefaults::apply($container);
    }
}
