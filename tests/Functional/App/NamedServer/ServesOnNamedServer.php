<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Serves a kernel's resources from a named server, mounted under `/v5`, instead of the
 * implicit `default` one, so a suite can run against a named server unchanged.
 */
trait ServesOnNamedServer
{
    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '-' . ResourcesOnServerPass::SERVER;
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        parent::configureContainer($container, $loader, $builder);

        $container->extension('json_api', ['servers' => [ResourcesOnServerPass::SERVER => ['base_uri' => '']]]);
        // After autoconfiguration has tagged the resources, before the bundle reads the tags.
        $builder->addCompilerPass(new ResourcesOnServerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(ResourcesOnServerPass::SERVER, JsonApiRouteLoader::ROUTE_TYPE)->prefix('/' . ResourcesOnServerPass::SERVER);
    }
}
