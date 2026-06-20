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
use haddowg\JsonApiBundle\Validation\FilterValueValidator;
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

    // The per-server, per-type route descriptor map (uriType / operations / tags /
    // resource-or-standalone shape) surfaced as a service so the OpenAPI
    // MetadataSource can enumerate a server's types — the same scalar descriptors
    // the ResourceLocatorPass hands to the JsonApiRouteLoader. Its argument is
    // filled by that pass.
    $services->set(\haddowg\JsonApiBundle\Server\RouteDescriptorRegistry::class)
        ->args(['$descriptorsByServer' => []]);

    // --- OpenAPI metadata source (Slice 4 stage A) ----------------------------

    // The pure helpers the MetadataSource composes: the paginator-class -> kind
    // discriminator, the humanized-type tag-name default, and the include-path
    // graph walk (autowired from the TypeMetadataResolver).
    $services->set(\haddowg\JsonApiBundle\OpenApi\Metadata\PaginatorKindResolver::class);
    $services->set(\haddowg\JsonApiBundle\OpenApi\Metadata\TagNameResolver::class);
    $services->set(\haddowg\JsonApiBundle\OpenApi\Metadata\IncludePathResolver::class);

    // The bundle implementation of core's OpenAPI metadata contract: it builds a
    // ServerMetadataInterface (+ its TypeMetadataInterface family) per server from
    // the live registry. The ResourceSecurityRegistry is optional (present only
    // when symfony/security-core is installed), so it is injected ->nullOnInvalid();
    // the per-server document config (info / servers / tags / security schemes) is
    // empty here and wired from json_api.openapi.* config in Slice 4 stage B.
    $services->set(\haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource::class)
        ->arg('$security', \Symfony\Component\DependencyInjection\Loader\Configurator\service(\haddowg\JsonApiBundle\Security\ResourceSecurityRegistry::class)->nullOnInvalid())
        ->arg('$configByServer', []);

    // Custom, non-CRUD actions (bundle ADR 0076). The ActionRegistry resolves an
    // ActionDescriptor + its ActionHandlerInterface by the composite key
    // (server, type, scope, path); both its handler service-locator and its
    // descriptor map are filled by the ResourceLocatorPass from the ACTION_TAG
    // services (mirroring the RelationsRegistry's lazy locator). The ActionInvoker
    // drives the per-action concerns (entity resolution, Document hydration +
    // validation, the before/after gates) and is injected — optionally — into the
    // single CrudOperationHandler's CustomActionOperation arm. Its validator and
    // dispatcher are optional, so an app without the Validator bridge / the
    // event-dispatcher still invokes actions (just unvalidated / un-hooked).
    $services->set(\haddowg\JsonApiBundle\Action\ActionRegistry::class)
        ->args(['$handlers' => null, '$descriptors' => []]);

    $services->set(\haddowg\JsonApiBundle\Action\ActionInvoker::class)
        ->arg('$validator', \Symfony\Component\DependencyInjection\Loader\Configurator\service(ResourceValidator::class)->nullOnInvalid())
        ->arg('$dispatcher', \Symfony\Component\DependencyInjection\Loader\Configurator\service('event_dispatcher')->nullOnInvalid());

    // Resolves a type's id encoder + route {id} pattern from its resource's Id
    // field (ADR 0038). The Doctrine provider/persister decode wire ids through it
    // (the SPI stays wire-id; the in-memory provider has no encoder so wire ==
    // storage and is untouched); the route loader reads the route pattern. It
    // resolves through the global ResourceLocator (autowired).
    $services->set(IdEncoderResolver::class);

    // --- Operations + data providers -----------------------------------------

    // The stateless factory that owns the related-collection vocabulary merge (the
    // resource⊕relation⊕pivot filter/sort assembly), the 3-tier per-relation
    // paginator chain, and the CollectionCriteria assembly — shared by the
    // related-collection endpoint (the handler) and its include/linkage windowing
    // twin (RelationshipWindowBatcher), so the logic lives once (bundle ADR 0057).
    // Autowired into both.
    $services->set(\haddowg\JsonApiBundle\DataProvider\RelationCriteriaFactory::class);

    // The validator argument is optional: it resolves to the ResourceValidator
    // only when the Symfony Validator bridge is wired (below), else null. The
    // dispatcher fires the per-operation lifecycle events (bundle ADR 0042); it is
    // optional too, so the handler is a no-op for hooks when symfony/event-dispatcher
    // is absent.
    $services->set(CrudOperationHandler::class)
        ->arg('$validator', \Symfony\Component\DependencyInjection\Loader\Configurator\service(ResourceValidator::class)->nullOnInvalid())
        ->arg('$dispatcher', \Symfony\Component\DependencyInjection\Loader\Configurator\service('event_dispatcher')->nullOnInvalid())
        // Optional too: resolves to the FilterValueValidator only when the Symfony
        // Validator bridge is wired (below), else null — a constrained filter is
        // then inert, matching how the resource validator degrades (ADR 0048).
        ->arg('$filterValues', \Symfony\Component\DependencyInjection\Loader\Configurator\service(FilterValueValidator::class)->nullOnInvalid())
        // The Relationship Queries profile window seam (bundle ADR 0053): the
        // per-request pagination holder threaded into the memoized Server (in
        // loadExtension) and a batcher that windows each rendered to-many relation
        // to page 1 of its relatedQuery-ordered/filtered set. Both autowired and
        // always present (the profile parse is gated on negotiation per request).
        ->arg('$windowBatcher', \Symfony\Component\DependencyInjection\Loader\Configurator\service(\haddowg\JsonApiBundle\DataProvider\RelationshipWindowBatcher::class))
        ->arg('$relationshipPagination', \Symfony\Component\DependencyInjection\Loader\Configurator\service(\haddowg\JsonApiBundle\Serializer\RequestScopedRelationshipPagination::class))
        // The provider-agnostic include batcher (bundle ADR 0062): batch eager-loads a
        // read's effective ?include tree one query per level through the
        // fetchRelatedCollectionBatch SPI, for every batching provider (Doctrine AND
        // in-memory). Always wired; a relation/provider that cannot batch falls to lazy.
        ->arg('$includeBatcher', \Symfony\Component\DependencyInjection\Loader\Configurator\service(\haddowg\JsonApiBundle\DataProvider\RelatedIncludeBatcher::class))
        // The custom-action invoker (bundle ADR 0076): the optional collaborator the
        // CustomActionOperation arm delegates to. Always wired in the bundle (a null
        // would 404 every action); an app with no actions simply registers none.
        ->arg('$actions', \Symfony\Component\DependencyInjection\Loader\Configurator\service(\haddowg\JsonApiBundle\Action\ActionInvoker::class));

    // The ?withCount count seam (bundle ADR 0052): a stable per-request holder
    // threaded into the memoized Server (in loadExtension) and a batcher that fills
    // it. The batcher runs once over a read's fetched page and asks the provider for
    // ONE grouped count per ?withCount-named countable relation (no N+1); the holder
    // is the swappable indirection so the immutable Server can render this page's
    // counts. Both autowired.
    $services->set(\haddowg\JsonApiBundle\Serializer\RequestScopedRelationshipCount::class);
    $services->set(\haddowg\JsonApiBundle\DataProvider\RelationCountBatcher::class);

    // The Relationship Queries profile window seam (bundle ADR 0053): a stable
    // per-request holder threaded into the memoized Server (in loadExtension) and a
    // batcher that fills it. The batcher windows each rendered to-many relation of a
    // read's fetched page to page 1 of its relatedQuery-ordered/filtered set (writing
    // that page back onto each parent so the linkage IS page 1); the holder is the
    // swappable indirection so the immutable Server can render this request's pages.
    // Both autowired.
    $services->set(\haddowg\JsonApiBundle\Serializer\RequestScopedRelationshipPagination::class);
    $services->set(\haddowg\JsonApiBundle\DataProvider\RelationshipWindowBatcher::class)
        // The optional filter-value validator: resolves to the FilterValueValidator
        // only when the Symfony Validator bridge is wired, else null — so a mistyped
        // relatedQuery filter value on the include/linkage path is the endpoint's same
        // 400, and a constrained filter is inert without the validator, exactly as the
        // handler degrades (bundle ADR 0068 follow-up #2).
        ->arg('$filterValues', \Symfony\Component\DependencyInjection\Loader\Configurator\service(FilterValueValidator::class)->nullOnInvalid());

    // The include batcher (bundle ADR 0062): a provider-agnostic orchestrator that
    // batch eager-loads a read's effective ?include tree one query per level through
    // the fetchRelatedCollectionBatch SPI (the successor to the Doctrine-only
    // IncludePreloader + shipmonk/doctrine-entity-preloader, now dissolved). Autowired
    // from the DataProviderRegistry, TypeMetadataResolver and ServerProvider; it carries
    // the disable() seam the conformance witness toggles to prove byte-identical output.
    $services->set(\haddowg\JsonApiBundle\DataProvider\RelatedIncludeBatcher::class);

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

    // The route-scoped kernel.response listener that emits the declarative cache
    // (gap G7) + deprecation/sunset (gap G16, RFC 8594) headers a resource declares
    // via #[AsJsonApiResource(cacheHeaders/deprecation/sunset)] or the global
    // json_api.defaults (bundle ADR 0054). It runs on kernel.response so the final
    // Response (data or error) is in hand and its status distinguishes a successful
    // read (cacheable) from an error (never cached). The ResponseHeadersRegistry is
    // registered in loadExtension (seeded with the global defaults; its per-type map
    // filled by the ResponseHeadersPass).
    $services->set(\haddowg\JsonApiBundle\EventListener\ResponseHeadersListener::class)
        ->args([
            '$registry' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(\haddowg\JsonApiBundle\Http\ResponseHeadersRegistry::class),
            '$targetResolver' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(TargetResolver::class),
        ])
        ->tag('kernel.event_listener', ['event' => 'kernel.response', 'method' => 'onKernelResponse']);

    // The exception listener owns JSON:API-route errors; high priority so it wins
    // over framework error handling on those routes.
    $services->set(ExceptionListener::class)
        ->args([
            '$servers' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(ServerProvider::class),
            '$psrHttpFactory' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(\Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory::class),
            '$httpFoundationFactory' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(\Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory::class),
            '$debug' => '%kernel.debug%',
            '$logger' => \Symfony\Component\DependencyInjection\Loader\Configurator\service('logger')->nullOnInvalid(),
            // The application-extensible exception → JSON:API-error mappers (bundle
            // ADR 0073), priority-ordered (mirrors how DOCTRINE_EXTENSION_TAG is
            // injected). Consulted only for a throwable that is NOT a core
            // JsonApiExceptionInterface; the config-driven ConfiguredExceptionMapper
            // (registered in loadExtension) sits at the low -1000 fallback priority,
            // so an app mapper (default 0) is consulted before the config map.
            '$mappers' => \Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator(JsonApiBundle::EXCEPTION_MAPPER_TAG),
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
        // priority -128: the reference provider is always the *fallback* — it
        // supports every entity-mapped type, so an application provider at the
        // default priority (0) shadows it for the types it supports without any
        // priority configuration.
        // Resolves a belongsToMany pivot relation's Doctrine association entity
        // (auto-detected from metadata, or its through() override) for the
        // provider's single-DQL pivot-collection fetch.
        $services->set(\haddowg\JsonApiBundle\DataProvider\Doctrine\PivotAssociationResolver::class)
            ->args([
                '$entityManager' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(\Doctrine\ORM\EntityManagerInterface::class),
            ]);

        $services->set(DoctrineDataProvider::class)
            ->args([
                '$entityManager' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(\Doctrine\ORM\EntityManagerInterface::class),
                '$entityClassByType' => [],
                '$idEncoders' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(IdEncoderResolver::class),
                '$extensions' => \Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator(JsonApiBundle::DOCTRINE_EXTENSION_TAG),
                '$pivotAssociations' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(\haddowg\JsonApiBundle\DataProvider\Doctrine\PivotAssociationResolver::class),
                // Whether the windowed-include batch runs the bounded ROW_NUMBER/COUNT
                // OVER native query (true, the default) or the per-parent bounded
                // fallback (false) — json_api.doctrine.window_functions (bundle ADR
                // 0065). Defaulted true so a provider wired outside the extension
                // (a plain service test) keeps the native path.
                '$windowFunctions' => '%haddowg_json_api.doctrine.window_functions%',
                // Author arms for custom FilterInterface / SortInterface types: each
                // autoconfigured arm pushes one custom value object down to DQL before
                // the handler raises UnsupportedFilter/UnsupportedSort (core ADR 0078).
                '$filterArms' => \Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator(JsonApiBundle::DOCTRINE_FILTER_ARM_TAG),
                '$sortArms' => \Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator(JsonApiBundle::DOCTRINE_SORT_ARM_TAG),
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
                // Resolves a belongsToMany pivot relation's association entity for the
                // writable-pivot association-entity diff (upsert / reorder / remove).
                '$pivotAssociations' => \Symfony\Component\DependencyInjection\Loader\Configurator\service(\haddowg\JsonApiBundle\DataProvider\Doctrine\PivotAssociationResolver::class),
            ])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG, ['priority' => -128]);

        // The storage-aware relationship load-state predicate, threaded into each
        // per-server Server by its ServerFactory (registered in loadExtension) via
        // core's withRelationshipLoadState injector. It reads a managed entity's
        // Doctrine metadata to answer — for
        // a relation that opted into dataOnlyWhenLoaded — whether a to-many
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

        // The filter-value twin of the ResourceValidator: validates client-supplied
        // filter[<key>] values against the value constraints a filter declares,
        // turning a mistyped value into a clean 400 before it reaches the provider
        // (ADR 0048). Reuses the same ConstraintTranslator bridge, so the filter
        // shortcuts need no new translator cases.
        $services->set(FilterValueValidator::class);
    }
};
