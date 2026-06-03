<?php

declare(strict_types=1);

use haddowg\JsonApiBundle\Controller\JsonApiController;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider;
use haddowg\JsonApiBundle\EventListener\ExceptionListener;
use haddowg\JsonApiBundle\EventListener\RequestListener;
use haddowg\JsonApiBundle\EventListener\ViewListener;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Operation\ReadOperationHandler;
use haddowg\JsonApiBundle\Operation\TargetResolver;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Server\ResourceLocator;
use haddowg\JsonApiBundle\Server\ServerFactory;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Phase-0 bundle service wiring.
 *
 * Registers the PSR-7 <-> HttpFoundation bridge, the immutable Server factory and
 * its provider, the read operation handler, the data-provider SPI registry, the
 * Target resolver + route loader, and the three kernel listeners that drive the
 * request lifecycle. Global/vendor classes are referenced inline with a leading
 * backslash (the CS config disables global_namespace_import).
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->private();

    // --- PSR-17 factories (Nyholm) + the HttpFoundation <-> PSR-7 bridge -------

    $services->set(\Nyholm\Psr7\Factory\Psr17Factory::class);
    $services->alias(\Psr\Http\Message\ResponseFactoryInterface::class, \Nyholm\Psr7\Factory\Psr17Factory::class);
    $services->alias(\Psr\Http\Message\StreamFactoryInterface::class, \Nyholm\Psr7\Factory\Psr17Factory::class);
    $services->alias(\Psr\Http\Message\ServerRequestFactoryInterface::class, \Nyholm\Psr7\Factory\Psr17Factory::class);
    $services->alias(\Psr\Http\Message\UploadedFileFactoryInterface::class, \Nyholm\Psr7\Factory\Psr17Factory::class);

    $services->set(\Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory::class)
        ->args([
            \Symfony\Component\DependencyInjection\Loader\Configurator\service(\Psr\Http\Message\ServerRequestFactoryInterface::class),
            \Symfony\Component\DependencyInjection\Loader\Configurator\service(\Psr\Http\Message\StreamFactoryInterface::class),
            \Symfony\Component\DependencyInjection\Loader\Configurator\service(\Psr\Http\Message\UploadedFileFactoryInterface::class),
            \Symfony\Component\DependencyInjection\Loader\Configurator\service(\Psr\Http\Message\ResponseFactoryInterface::class),
        ]);

    $services->set(\Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory::class);

    // --- Resource discovery + Server -----------------------------------------

    // The locator's arguments are filled by ResourceLocatorPass from the tagged
    // Resource services (a class-string-keyed service locator + the class list).
    $services->set(ResourceLocator::class)
        ->args(['$services' => null, '$classes' => []]);

    $services->set(ServerFactory::class)
        ->args([
            '$resources' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(ResourceLocator::class),
            '$responseFactory' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(\Psr\Http\Message\ResponseFactoryInterface::class),
            '$streamFactory' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(\Psr\Http\Message\StreamFactoryInterface::class),
            '$baseUri' => '%haddowg_json_api.base_uri%',
            '$version' => '%haddowg_json_api.version%',
            '$handler' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(ReadOperationHandler::class),
        ]);

    $services->set(ServerProvider::class);

    // --- Operations + data providers -----------------------------------------

    $services->set(ReadOperationHandler::class);

    // Core's stateless verb x target-shape dispatch factory; autowired into the
    // RequestListener so the bundle reuses core's operation-construction decision.
    $services->set(\haddowg\JsonApi\Operation\OperationFactory::class);

    $services->set(TargetResolver::class);

    $services->set(DataProviderRegistry::class)
        ->args([
            '$providers' => \Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator(JsonApiBundle::DATA_PROVIDER_TAG),
        ]);

    // --- Routing + controller -------------------------------------------------

    $services->set(JsonApiRouteLoader::class)
        ->tag('routing.loader');

    // The pass-through controller every generated route resolves to; the
    // RequestListener has already produced the response VO it returns.
    $services->set(JsonApiController::class)
        ->public()
        ->tag('controller.service_arguments');

    // --- Kernel listeners -----------------------------------------------------

    // RequestListener runs after Symfony's RouterListener (priority 32) so route
    // defaults (_jsonapi_type/_jsonapi_server) are populated first.
    $services->set(RequestListener::class)
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onKernelRequest', 'priority' => 16]);

    $services->set(ViewListener::class)
        ->tag('kernel.event_listener', ['event' => 'kernel.view', 'method' => 'onKernelView']);

    // The exception listener owns JSON:API-route errors; high priority so it wins
    // over framework error handling on those routes.
    $services->set(ExceptionListener::class)
        ->args([
            '$servers' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(ServerProvider::class),
            '$psrHttpFactory' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(\Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory::class),
            '$httpFoundationFactory' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(\Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory::class),
            '$debug' => '%kernel.debug%',
            '$logger' => \Symfony\Component\DependencyInjection\Loader\Configurator\service('logger')->nullOnInvalid(),
        ])
        ->tag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'onKernelException', 'priority' => 128]);

    // --- Doctrine reference provider (only when doctrine/orm is installed) -----

    if (\class_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
        $services->set(DoctrineDataProvider::class)
            ->args([
                '$entityManager' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(\Doctrine\ORM\EntityManagerInterface::class),
                '$entityClassByType' => [],
            ])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
    }
};
