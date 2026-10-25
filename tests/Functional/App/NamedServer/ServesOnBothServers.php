<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Serves a kernel's resources from the named server, as {@see ServesOnNamedServer}
 * does, and a second `securedWidgets` resource class from the default server, so the
 * same type name carries different security on each.
 */
trait ServesOnBothServers
{
    use ServesOnNamedServer {
        configureContainer as private configureNamedServer;
        configureRoutes as private configureNamedServerRoutes;
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '-both-servers';
    }

    /**
     * @return class-string
     */
    abstract protected function defaultServerResource(): string;

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $this->configureNamedServer($container, $loader, $builder);

        // A scheme to document, so a secured operation shows its requirement and a 401.
        $container->extension('json_api', ['openapi' => ['security' => [
            'schemes' => ['bearer' => ['type' => 'bearer']],
            'default_requirement' => 'bearer',
        ]]]);

        $container->services()->defaults()->autowire()->autoconfigure()->set($this->defaultServerResource());
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $this->configureNamedServerRoutes($routes);
    }
}
