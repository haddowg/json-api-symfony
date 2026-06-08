<?php

declare(strict_types=1);

use haddowg\JsonApiBundle\Controller\JsonApiController;
use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiBundle\DataPersister\Doctrine\DoctrineDataPersister;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider;
use haddowg\JsonApiBundle\EventListener\ExceptionListener;
use haddowg\JsonApiBundle\EventListener\RequestListener;
use haddowg\JsonApiBundle\EventListener\ViewListener;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Operation\CrudOperationHandler;
use haddowg\JsonApiBundle\Operation\TargetResolver;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Server\ResourceLocator;
use haddowg\JsonApiBundle\Server\ServerFactory;
use haddowg\JsonApiBundle\Server\ServerProvider;
use haddowg\JsonApiBundle\Validation\ConstraintTranslator;
use haddowg\JsonApiBundle\Validation\JsonPointerBuilder;
use haddowg\JsonApiBundle\Validation\ResourceValidator;
use haddowg\JsonApiBundle\Validation\StrictEmailConstraintTranslator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Bundle service wiring.
 *
 * Registers the PSR-7 <-> HttpFoundation bridge, the immutable Server factory and
 * its provider, the CRUD operation handler, the data-provider and data-persister
 * SPI registries, the Target resolver + route loader, and the three kernel
 * listeners that drive the request lifecycle. Global/vendor classes are
 * referenced inline with a leading backslash (the CS config disables
 * global_namespace_import).
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
            '$handler' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(CrudOperationHandler::class),
        ]);

    $services->set(ServerProvider::class);

    // --- Operations + data providers -----------------------------------------

    // The validator argument is optional: it resolves to the ResourceValidator
    // only when the Symfony Validator bridge is wired (below), else null.
    $services->set(CrudOperationHandler::class)
        ->arg('$validator', \Symfony\Component\DependencyInjection\Loader\Configurator\service(ResourceValidator::class)->nullOnInvalid());

    // Core's stateless verb x target-shape dispatch factory; autowired into the
    // RequestListener so the bundle reuses core's operation-construction decision.
    $services->set(\haddowg\JsonApi\Operation\OperationFactory::class);

    $services->set(TargetResolver::class);

    $services->set(DataProviderRegistry::class)
        ->args([
            '$providers' => \Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator(JsonApiBundle::DATA_PROVIDER_TAG),
        ]);

    $services->set(DataPersisterRegistry::class)
        ->args([
            '$persisters' => \Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator(JsonApiBundle::DATA_PERSISTER_TAG),
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
    // defaults (_jsonapi_type/_jsonapi_server) are populated first. Its schema
    // validator is null unless json_api.schema_validation registered the optional
    // opis DocumentValidator.
    $services->set(RequestListener::class)
        ->arg('$schemaValidator', \Symfony\Component\DependencyInjection\Loader\Configurator\service(\haddowg\JsonApi\Validation\DocumentValidator::class)->nullOnInvalid())
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

    // interface_exists, not class_exists: EntityManagerInterface is an interface.
    // The DoctrineEntityMapPass removes this definition again when no resource
    // maps an entity, so non-Doctrine applications never reference the (absent)
    // EntityManagerInterface service.
    if (\interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
        // priority -128: the reference provider is always the *fallback* — it
        // supports every entity-mapped type, so an application provider at the
        // default priority (0) shadows it for the types it supports without any
        // priority configuration.
        $services->set(DoctrineDataProvider::class)
            ->args([
                '$entityManager' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(\Doctrine\ORM\EntityManagerInterface::class),
                '$entityClassByType' => [],
                '$extensions' => \Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator(JsonApiBundle::DOCTRINE_EXTENSION_TAG),
            ])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG, ['priority' => -128]);

        // The write twin, same -128 fallback. Both $entityClassByType arguments
        // are filled by the DoctrineEntityMapPass (which also removes both
        // definitions when no resource maps an entity).
        $services->set(DoctrineDataPersister::class)
            ->args([
                '$entityManager' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(\Doctrine\ORM\EntityManagerInterface::class),
                '$entityClassByType' => [],
            ])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG, ['priority' => -128]);
    }

    // --- Symfony Validator bridge (only when symfony/validator is installed) ---

    // symfony/validator is a `suggest` dependency: without it the bridge stays
    // absent and the CrudOperationHandler's optional validator is null, so writes
    // run unvalidated. With it, the ResourceValidator translates each resource's
    // declared constraints to Symfony rules and renders violations as 422s.
    if (\interface_exists(\Symfony\Component\Validator\Validator\ValidatorInterface::class)) {
        $services->set(JsonPointerBuilder::class);

        // The one Custom translator core needs (`email.strict`); autoconfigured to
        // the custom-translator tag. Applications add their own the same way.
        $services->set(StrictEmailConstraintTranslator::class);

        $services->set(ConstraintTranslator::class)
            ->arg('$customTranslators', \Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator(JsonApiBundle::CUSTOM_CONSTRAINT_TRANSLATOR_TAG));

        $services->set(ResourceValidator::class);
    }
};
