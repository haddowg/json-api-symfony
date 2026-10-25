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
 * does, plus resource classes that name their own server, so the same type name can
 * be served by a different class on each server.
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
     * The resource and action classes that name their own server.
     *
     * @return list<class-string>
     */
    abstract protected function ownServerResources(): array;

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $this->configureNamedServer($container, $loader, $builder);

        // A scheme to document, so a secured operation shows its requirement and a 401.
        $container->extension('json_api', ['openapi' => ['security' => [
            'schemes' => ['bearer' => ['type' => 'bearer']],
            'default_requirement' => 'bearer',
        ]]]);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        foreach ($this->ownServerResources() as $resource) {
            $services->set($resource);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $this->configureNamedServerRoutes($routes);
    }
}
