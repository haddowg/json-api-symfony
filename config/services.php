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
use haddowg\JsonApiBundle\Server\IdEncoderResolver;
use haddowg\JsonApiBundle\Server\RelationsRegistry;
use haddowg\JsonApiBundle\Server\ResourceLocator;
use haddowg\JsonApiBundle\Server\ServerProvider;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;
use haddowg\JsonApiBundle\Validation\ConstraintTranslator;
use haddowg\JsonApiBundle\Validation\JsonPointerBuilder;
use haddowg\JsonApiBundle\Validation\ResourceValidator;
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

    // The per-server ServerFactory services and the ServerProvider are registered
    // in JsonApiBundle::loadExtension() (one factory per declared server, ADR 0034),
    // not here — the server map is only known once the extension config is read.

    // The type-keyed registry of standalone relations providers; the
    // ResourceLocatorPass fills its locator argument from the tagged providers
    // (mirroring the ResourceLocator's $services), so a resource-less type can
    // still declare relations (ADR 0026).
    $services->set(RelationsRegistry::class)
        ->args(['$providers' => null]);

    // The resource-presence-aware metadata lookup the CRUD engine resolves a
    // type's resource/relations through, so a bare serializer/hydrator pair (no
    // resource) is tolerated without per-type branching (ADR 0021). It sources
    // relations resource-first then from the RelationsRegistry (autowired).
    $services->set(TypeMetadataResolver::class);

    // Resolves a type's id encoder + route {id} pattern from its resource's Id
    // field (ADR 0038). The Doctrine provider/persister decode wire ids through it
    // (the SPI stays wire-id; the in-memory provider has no encoder so wire ==
    // storage and is untouched); the route loader reads the route pattern. It
    // resolves through the global ResourceLocator (autowired).
    $services->set(IdEncoderResolver::class);

    // --- Operations + data providers -----------------------------------------

    // The validator argument is optional: it resolves to the ResourceValidator
    // only when the Symfony Validator bridge is wired (below), else null. The
    // dispatcher fires the per-operation lifecycle events (bundle ADR 0042); it is
    // optional too, so the handler is a no-op for hooks when symfony/event-dispatcher
    // is absent.
    $services->set(CrudOperationHandler::class)
        ->arg('$validator', \Symfony\Component\DependencyInjection\Loader\Configurator\service(ResourceValidator::class)->nullOnInvalid())
        ->arg('$dispatcher', \Symfony\Component\DependencyInjection\Loader\Configurator\service('event_dispatcher')->nullOnInvalid());

    // The built-in subscriber that routes each lifecycle event to the resource's
    // overridable hook method (ResourceLifecycleHooksInterface), making the
    // per-type resource methods sugar over the events (bundle ADR 0042). It is an
    // EventSubscriberInterface, so framework-bundle autoconfigures it as a
    // kernel.event_subscriber.
    $services->set(\haddowg\JsonApiBundle\EventListener\ResourceHookSubscriber::class);

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
        ->arg('$idEncoders', \Symfony\Component\DependencyInjection\Loader\Configurator\service(IdEncoderResolver::class))
        ->tag('routing.loader');

    // The pass-through controller every generated route resolves to; the
    // RequestListener has already produced the response VO it returns.
    $services->set(JsonApiController::class)
        ->public()
        ->tag('controller.service_arguments');

    // --- Kernel listeners -----------------------------------------------------

    // RequestListener runs after Symfony's RouterListener (priority 32) so route
    // defaults (_jsonapi_type/_jsonapi_server) are populated first, AND after the
    // Security Firewall (priority 8) so an authenticated token is in the token
    // storage before the listener dispatches the operation — the declarative
    // authorization layer (bundle ADR 0043) evaluates is_granted() at the lifecycle
    // hooks the dispatch fires, so the firewall must have authenticated first.
    // Priority 4 keeps it after the firewall but well before the controller is
    // resolved. Its schema validator is null unless json_api.schema_validation
    // registered the optional opis DocumentValidator.
    $services->set(RequestListener::class)
        ->arg('$schemaValidator', \Symfony\Component\DependencyInjection\Loader\Configurator\service(\haddowg\JsonApi\Validation\DocumentValidator::class)->nullOnInvalid())
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onKernelRequest', 'priority' => 4]);

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
            // Optional Symfony Security collaborators (present only with
            // symfony/security-core), so an AccessDeniedException maps to 401 when
            // unauthenticated / 403 when authenticated-but-denied (bundle ADR 0043).
            '$tokenStorage' => \Symfony\Component\DependencyInjection\Loader\Configurator\service('security.token_storage')->nullOnInvalid(),
            '$trustResolver' => \Symfony\Component\DependencyInjection\Loader\Configurator\service('security.authentication.trust_resolver')->nullOnInvalid(),
        ])
        ->tag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'onKernelException', 'priority' => 128]);

    // --- Doctrine reference provider (only when doctrine/orm is installed) -----

    // interface_exists, not class_exists: EntityManagerInterface is an interface.
    // The DoctrineEntityMapPass removes this definition again when no resource
    // maps an entity, so non-Doctrine applications never reference the (absent)
    // EntityManagerInterface service.
    if (\interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
        // The include batch-preloader — wired only when the optional
        // shipmonk/doctrine-entity-preloader library is installed. The
        // DoctrineDataProvider injects it with ->nullOnInvalid() below, so without
        // the library the provider's preload capability is a no-op and includes
        // render lazily (ADR 0035).
        if (\class_exists(\ShipMonk\DoctrineEntityPreloader\EntityPreloader::class)) {
            $services->set(\ShipMonk\DoctrineEntityPreloader\EntityPreloader::class)
                ->args([
                    \Symfony\Component\DependencyInjection\Loader\Configurator\service(\Doctrine\ORM\EntityManagerInterface::class),
                ]);

            $services->set(\haddowg\JsonApiBundle\DataProvider\Doctrine\IncludePreloader::class)
                ->args([
                    '$entityManager' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(\Doctrine\ORM\EntityManagerInterface::class),
                    '$preloader' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(\ShipMonk\DoctrineEntityPreloader\EntityPreloader::class),
                ]);
        }

        // priority -128: the reference provider is always the *fallback* — it
        // supports every entity-mapped type, so an application provider at the
        // default priority (0) shadows it for the types it supports without any
        // priority configuration.
        $services->set(DoctrineDataProvider::class)
            ->args([
                '$entityManager' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(\Doctrine\ORM\EntityManagerInterface::class),
                '$entityClassByType' => [],
                '$idEncoders' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(IdEncoderResolver::class),
                '$extensions' => \Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator(JsonApiBundle::DOCTRINE_EXTENSION_TAG),
                // null when shipmonk/doctrine-entity-preloader is absent → lazy includes.
                '$preloader' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(\haddowg\JsonApiBundle\DataProvider\Doctrine\IncludePreloader::class)->nullOnInvalid(),
            ])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG, ['priority' => -128]);

        // The write twin, same -128 fallback. Both $entityClassByType arguments
        // are filled by the DoctrineEntityMapPass (which also removes both
        // definitions when no resource maps an entity).
        $services->set(DoctrineDataPersister::class)
            ->args([
                '$entityManager' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(\Doctrine\ORM\EntityManagerInterface::class),
                '$entityClassByType' => [],
                '$idEncoders' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(IdEncoderResolver::class),
            ])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG, ['priority' => -128]);

        // The storage-aware relationship load-state predicate, threaded into each
        // per-server Server by its ServerFactory (registered in loadExtension) via
        // core's withRelationshipLoadState injector. It reads a managed entity's
        // Doctrine metadata to answer — for
        // a relation that opted into linkageOnlyWhenLoaded — whether a to-many
        // association is an initialised PersistentCollection (without iterating
        // it); to-one is always loaded (a lazy proxy carries its id). The
        // DoctrineEntityMapPass removes it alongside the provider/persister when no
        // resource maps an entity.
        $services->set(\haddowg\JsonApiBundle\Serializer\Doctrine\DoctrineRelationshipLoadState::class)
            ->args([
                '$entityManager' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(\Doctrine\ORM\EntityManagerInterface::class),
            ]);
    }

    // --- Symfony Validator bridge (only when symfony/validator is installed) ---

    // symfony/validator is a `suggest` dependency: without it the bridge stays
    // absent and the CrudOperationHandler's optional validator is null, so writes
    // run unvalidated. With it, the ResourceValidator translates each resource's
    // declared constraints to Symfony rules and renders violations as 422s.
    if (\interface_exists(\Symfony\Component\Validator\Validator\ValidatorInterface::class)) {
        $services->set(JsonPointerBuilder::class);

        // Applications register translators for their own constraint VOs by tagging
        // a ConstraintTranslatorInterface; the translator consults them for anything
        // outside the built-in vocabulary.
        $services->set(ConstraintTranslator::class)
            ->arg('$extensionTranslators', \Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator(JsonApiBundle::CONSTRAINT_TRANSLATOR_TAG));

        $services->set(ResourceValidator::class);
    }
};
