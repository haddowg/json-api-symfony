<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\SecurityTestKernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The Doctrine per-server kernel: `cachedWidgets`, `plainWidgets` and `taggedWidgets`
 * served by a different class on each server, over global defaults of a public 120s
 * cache and `Deprecation: true`, with an untagged action on two of them.
 */
class BothServersResponseHeadersTestKernel extends SecurityTestKernel
{
    use ServesOnBothServers {
        configureContainer as private configureBothServers;
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '-response-headers';
    }

    protected function ownServerResources(): array
    {
        return [
            DefaultServerCachedWidgetResource::class,
            NamedServerCachedWidgetResource::class,
            DefaultServerPlainWidgetResource::class,
            NamedServerPlainWidgetResource::class,
            DefaultServerTaggedWidgetResource::class,
            NamedServerTaggedWidgetResource::class,
            PingTaggedWidget::class,
            PingPlainWidget::class,
        ];
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $this->configureBothServers($container, $loader, $builder);

        $container->extension('json_api', ['defaults' => [
            'cache_headers' => ['max_age' => 120, 'public' => true],
            'deprecation' => true,
        ]]);
    }
}
