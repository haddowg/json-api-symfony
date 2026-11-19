<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Action;

use Doctrine\Common\Collections\Collection;
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Operation\CustomActionOperation;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Request\MediaType;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\ForeignKey;
use haddowg\JsonApi\Resource\Field\GuardsMembershipInterface;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\FilterMatcher;
use haddowg\JsonApi\Resource\MetaContext;
use haddowg\JsonApi\Resource\WrittenMeta;
use haddowg\JsonApi\Response\AcceptedResponse;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Response\RawResponse;
use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApi\Server\ResolvingServerInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\DataPersister\AfterCommit;
use haddowg\JsonApiBundle\DataPersister\DataPersisterInterface;
use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiBundle\DataPersister\WriteTransaction;
use haddowg\JsonApiBundle\DataPersister\WriteTransactionContext;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\CurrentMembers;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;
use haddowg\JsonApiBundle\DataProvider\FilterPaths;
use haddowg\JsonApiBundle\DataProvider\QueriesFilteredCollectionInterface;
use haddowg\JsonApiBundle\DataProvider\RelatedReadRouter;
use haddowg\JsonApiBundle\DataProvider\ScopedLinkageGuard;
use haddowg\JsonApiBundle\Event\AfterActionEvent;
use haddowg\JsonApiBundle\Event\BeforeActionEvent;
use haddowg\JsonApiBundle\Event\BeforeFetchCollectionEvent;
use haddowg\JsonApiBundle\Operation\RelationFillHook;
use haddowg\JsonApiBundle\Operation\RequestMetaResolver;
use haddowg\JsonApiBundle\Server\ServerProvider;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;
use haddowg\JsonApiBundle\Validation\FilterValueValidator;
use haddowg\JsonApiBundle\Validation\ReadOnlyAttributeGuard;
use haddowg\JsonApiBundle\Validation\ResourceValidator;
use haddowg\JsonApiBundle\Validation\ValidationFailed;
use haddowg\JsonApiBundle\Validation\WireTypeValidator;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Invokes a custom, non-CRUD action. The single
 * bundle {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler} delegates its
 * {@see CustomActionOperation} arm here when this optional collaborator is wired
 * (`null` otherwise → the handler renders a `404`).
 *
 * `invoke()` resolves the {@see ActionDescriptor} + {@see ActionHandlerInterface}
 * from the {@see ActionRegistry} by the composite key
 * `(server, type, scope, action)` (a `404` when none); for a resource-scope action
 * it fetches the entity through the type's `DataProvider` (a `404` when absent);
 * for a {@see ActionInput::Document} action it resolves, validates (through the
 * Validator bridge) and hydrates the request document into an `inputType` instance;
 * it then fires the per-action {@see BeforeActionEvent} authz/lifecycle gate, builds
 * the {@see ActionContext}, calls the handler, fires {@see AfterActionEvent}, and
 * returns the handler's response value object.
 *
 * The request-wide serving gate and the strict-query validation are inherited for
 * free from `Server::dispatch()` (the operation is a first-class
 * {@see \haddowg\JsonApi\Operation\JsonApiOperationInterface}), so this invoker
 * owns only the per-action concerns.
 *
 * A collection-scope action that takes its type's filters first passes the type's
 * collection read gate, the {@see BeforeFetchCollectionEvent} a `GET /{type}` passes, and
 * has its `filter[…]` parameters checked as the collection fetch checks them: a key it
 * does not take is a `400`, and so is a value its filter's constraints reject. Both run
 * before the action's own gate and its handler.
 */
final readonly class ActionInvoker
{
    public function __construct(
        private ActionRegistry $registry,
        private DataProviderRegistry $providers,
        private DataPersisterRegistry $persisters,
        private TypeMetadataResolver $types,
        private ?ResourceValidator $validator = null,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?FilterValueValidator $filterValues = null,
        private ?WriteTransactionContext $txContext = null,
        private ?AfterCommit $afterCommit = null,
        private WireTypeValidator $wireTypes = new WireTypeValidator(),
        private ?ScopedLinkageGuard $linkageGuard = null,
        private ?RelatedReadRouter $readRouter = null,
        private ?RequestMetaResolver $requestMeta = null,
    ) {}

    public function invoke(CustomActionOperation $operation): DataResponse|MetaResponse|NoContentResponse|AcceptedResponse|SeeOtherResponse|ErrorResponse|RawResponse
    {
        $server = $operation->context()->server;
        $target = $operation->target();
        $type = $target->type;
        // A singleton target addresses the caller's resource even before its id is known.
        $scope = $target->addressesResource() ? ActionScope::Resource : ActionScope::Collection;

        $serverName = $this->serverName($operation);

        $descriptor = $this->registry->descriptorFor($serverName, $type, $scope, $operation->action());
        if ($descriptor === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        $handler = $this->registry->handlerFor($descriptor);

        // Resource scope: resolve the {id} to an entity before the handler runs; a
        // missing entity is a 404, exactly as the CRUD read/update/delete arms.
        $entity = null;
        if ($scope === ActionScope::Resource) {
            $id = $target->id;
            $entity = $id !== null ? $this->providers->forType($type)->fetchOne($type, $id) : null;
            if ($entity === null) {
                return ErrorResponse::fromException(new ResourceNotFound());
            }
        }

        // The call's top-level meta, and a document's `data.meta`, are validated against
        // what the handler and the input type declare, and their typed values ride the
        // request the hydrator and the handler read.
        $written = $this->writtenMeta($server, $descriptor, $handler, $operation);

        // Document input: validate then hydrate the request document into a fresh
        // inputType instance (None/Raw carry no document, so input stays null).
        $input = null;
        if ($this->inputMode($operation, $descriptor) === ActionInput::Document) {
            $body = $operation->body();
            if ($body === null) {
                return ErrorResponse::fromException(new ResourceNotFound());
            }

            $input = $this->resolveAndHydrateInput($server, $descriptor, $handler, $written->on($body), $entity);
        }

        $filtered = $descriptor->takesFilters()
            ? $this->filteredCollection($operation, $server, $descriptor, $serverName)
            : null;

        $context = new ActionContext(
            $server,
            $descriptor,
            $entity,
            $input,
            $written->on($this->request($operation)),
            $operation->queryParameters(),
            $filtered,
            $this->afterCommit,
        );

        // An action called with a writing method runs from its before gate to its after event
        // in one transaction on its type's persister, so a write it made is undone when
        // anything up to the response throws. Any action, writing or not, runs the callbacks
        // it registered once it returns.
        $persister = $this->writes($operation) ? $this->persisterFor($descriptor) : null;

        return WriteTransaction::run($persister, $this->txContext, afterCommit: $this->afterCommit, write: function () use ($type, $descriptor, $entity, $handler, $context): DataResponse|MetaResponse|NoContentResponse|AcceptedResponse|SeeOtherResponse|ErrorResponse|RawResponse {
            // Per-action before-gate: the security expression is evaluated against the
            // subject (the entity for resource scope, null for collection scope) by the
            // security subscriber, which throws to deny with a 403; a lifecycle consumer
            // may also abort here.
            $this->dispatch(new BeforeActionEvent($type, $descriptor->path, $entity, $descriptor->security));

            $response = $handler->handle($context);

            // A raw body is sent only in the media type the client's Accept picked.
            if ($response instanceof RawResponse && MediaType::essence($response->contentType()) !== $context->responseMediaType()) {
                throw new \LogicException(\sprintf(
                    'The action "%s" on type "%s" answered with a raw "%s" body, but the request negotiated "%s". Answer in $context->responseMediaType().',
                    $descriptor->path,
                    $type,
                    $response->contentType(),
                    $context->responseMediaType() ?? 'none',
                ));
            }

            $this->dispatch(new AfterActionEvent($type, $descriptor->path, $entity));

            return $response;
        });
    }

    /**
     * Validates the call's top-level `meta` against the members the handler declares for its
     * method, and a document input's `data.meta` against its input type's create
     * `resourceMeta()`. A raw body carries no JSON:API `meta`.
     *
     * @throws ValidationFailed
     */
    private function writtenMeta(ResolvingServerInterface $server, ActionDescriptor $descriptor, ActionHandlerInterface $handler, CustomActionOperation $operation): WrittenMeta
    {
        $mode = $this->inputMode($operation, $descriptor);
        if ($this->requestMeta === null || $mode === ActionInput::Raw) {
            return new WrittenMeta();
        }

        $request = $this->request($operation);
        $shape = $this->requestMeta->forAction($handler, $request->getMethod(), \sprintf('"%s" action of type "%s"', $descriptor->path, $descriptor->type));
        $document = $shape->isEmpty() ? null : RequestMetaResolver::documentMetaOf($request);
        $errors = RequestMetaResolver::errors($this->validator, $shape, $document, '/meta', true, $request);

        $resourceShape = null;
        $resource = null;
        if ($mode === ActionInput::Document && $server instanceof Server && $descriptor->inputType !== '') {
            $data = $request->getResource();
            $resource = \is_array($data) && \array_key_exists('meta', $data) ? $data['meta'] : null;
            $resourceShape = $this->requestMeta->forResource($server, $descriptor->inputType, MetaContext::write(OperationType::Create));
            $errors = [...$errors, ...RequestMetaResolver::errors($this->validator, $resourceShape, $resource, '/data/meta', true, $request)];
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        return new WrittenMeta(
            \is_array($document) ? $shape->values($document, true, $request) : [],
            $resourceShape !== null && \is_array($resource) ? $resourceShape->values($resource, true, $request) : [],
        );
    }

    /**
     * Whether the action was called with a method that writes: POST, PATCH, PUT or DELETE.
     */
    private function writes(CustomActionOperation $operation): bool
    {
        return \in_array(\strtoupper($this->request($operation)->getMethod()), ['POST', 'PATCH', 'PUT', 'DELETE'], true);
    }

    /**
     * The persister whose transaction a writing action runs in: its mount type's, else, for
     * a mount type with no persister, the persister of the type its document reads in.
     * Null when neither has one, and the action runs as it is.
     */
    private function persisterFor(ActionDescriptor $descriptor): ?DataPersisterInterface
    {
        foreach ([$descriptor->type, $descriptor->inputType] as $type) {
            if ($type !== '' && $this->persisters->supportsType($type)) {
                return $this->persisters->forType($type);
            }
        }

        return null;
    }

    /**
     * Runs the type's collection read gate, checks the requested filters against the ones
     * the action takes, and returns the factory {@see ActionContext::filtered()} calls.
     *
     * @return \Closure(): FilteredCollection
     */
    private function filteredCollection(
        CustomActionOperation $operation,
        ResolvingServerInterface $server,
        ActionDescriptor $descriptor,
        string $serverName,
    ): \Closure {
        $type = $descriptor->type;
        $request = $this->request($operation);

        // The gate a GET /{type} passes: securityList, or an app's own subscriber.
        $this->dispatch(new BeforeFetchCollectionEvent($type, $request, $serverName));

        $resource = $server instanceof Server ? $this->types->resourceFor($server, $type) : null;
        $filters = self::filtersTaken($resource?->allFilters() ?? [], $descriptor->filters);

        // Resolving the directives rejects a key the action does not take, a family
        // included, and a list filter's value of the wrong shape.
        $requested = $operation->queryParameters()->filter;
        FilterMatcher::directives($requested, $filters, $request);

        $paths = FilterPaths::of($server, $resource);
        $this->filterValues?->validate($requested, $filters, $request, $paths);

        $criteria = new CollectionCriteria(
            new QueryParameters(fields: [], includes: [], sort: [], filter: $requested, pagination: []),
            $filters,
            request: $request,
            paths: $paths,
        );

        return function () use ($type, $criteria, $descriptor): FilteredCollection {
            $provider = $this->providers->forType($type);
            if (!$provider instanceof QueriesFilteredCollectionInterface) {
                throw new \LogicException(\sprintf(
                    'The "%s" action on type "%s" asked for its filtered collection, but the type\'s data provider %s cannot query one. Implement %s on it.',
                    $descriptor->path,
                    $type,
                    $provider::class,
                    QueriesFilteredCollectionInterface::class,
                ));
            }

            return new FilteredCollection($provider, $type, $criteria, $descriptor);
        };
    }

    /**
     * The type's filters the action takes, in the type's order: every one for `true`, the
     * named ones for a list. The same rule as core's `ActionDeclarations::filters()`, which
     * the OpenAPI document is projected with.
     *
     * @param list<FilterInterface> $filters
     * @param bool|list<string>     $taken
     *
     * @return list<FilterInterface>
     */
    private static function filtersTaken(array $filters, bool|array $taken): array
    {
        if ($taken === true) {
            return $filters;
        }
        if ($taken === false) {
            return [];
        }

        return \array_values(\array_filter(
            $filters,
            static fn(FilterInterface $filter): bool => \in_array($filter->key(), $taken, true),
        ));
    }

    /**
     * The mode the request body was read in: the media type it matched picks it when
     * the action lists accepted types, otherwise the action's declared input.
     */
    private function inputMode(CustomActionOperation $operation, ActionDescriptor $descriptor): ActionInput
    {
        $input = $this->request($operation)->getAttribute(ActionContext::INPUT_ATTRIBUTE);

        return $input instanceof ActionInput ? $input : $descriptor->input;
    }

    /**
     * Resolves the blank input instance, runs the Validator bridge against the
     * `inputType`, then hydrates the request body onto it — mirroring the
     * CrudOperationHandler's validate-then-hydrate create idiom. The blank instance
     * is supplied by the handler when it implements
     * {@see ActionInputFactoryInterface} (a bespoke command DTO with no persister),
     * else by the `inputType` persister's `instantiate()`. `$entity` is the resource a
     * resource-scope action was called on, `null` for a collection action.
     */
    private function resolveAndHydrateInput(
        ResolvingServerInterface $server,
        ActionDescriptor $descriptor,
        ActionHandlerInterface $handler,
        JsonApiRequestInterface $body,
        ?object $entity,
    ): object {
        $inputType = $descriptor->inputType;

        $instance = $handler instanceof ActionInputFactoryInterface
            ? $handler->newInput($body)
            : $this->persisters->forType($inputType)->instantiate($inputType);

        $this->validate($server, $inputType, $body);

        if ($handler instanceof ActionInputFactoryInterface) {
            [$instance, $body] = $this->fillUnguarded($server, $inputType, $instance, $body);
        }

        $resource = $handler instanceof ActionInputFactoryInterface || !$server instanceof Server ? null : $this->types->resourceFor($server, $inputType);
        if ($resource !== null) {
            // The input's members are compared with the resource the action was called on when
            // the input reads as that resource's type, otherwise with the blank instance.
            $stored = $entity !== null && $inputType === $descriptor->type ? $entity : null;
            [$instance, $body] = $this->relateInput($server, $resource, $instance, $body, $stored);
        }

        $hydrator = $server->hydratorFor($inputType);
        $hydrated = $hydrator->hydrate($body, $instance);
        \assert(\is_object($hydrated));

        // A resource hands `data.meta` to its declared members' hooks as it hydrates; a
        // composed hydrator's type has them applied here.
        $data = $body->getResource();
        $meta = \is_array($data) ? ($data['meta'] ?? null) : null;
        if (!$hydrator instanceof \haddowg\JsonApi\Resource\AbstractResource && $this->requestMeta !== null && $server instanceof Server && \is_array($meta) && $meta !== []) {
            $filled = $this->requestMeta->forResource($server, $inputType, MetaContext::write(OperationType::Create))->fill($hydrated, $meta, true, $body);
            $hydrated = \is_object($filled) ? $filled : $hydrated;
        }

        return $hydrated;
    }

    /**
     * Fills each to-many declared `cannotChangeMembership()` that a `PATCH` input names onto an
     * input from {@see ActionInputFactoryInterface}, as core's hydration would but without
     * comparing its members, and returns the document without them. The factory's object is
     * no stored resource, so the factory or the handler decides what a change of them means.
     *
     * @return array{object, JsonApiRequestInterface}
     */
    private function fillUnguarded(ResolvingServerInterface $server, string $inputType, object $instance, JsonApiRequestInterface $body): array
    {
        $resource = $server instanceof Server && $body->getMethod() === 'PATCH' ? $this->types->resourceFor($server, $inputType) : null;
        /** @var array<string, mixed> $document */
        $document = (array) $body->getParsedBody();
        $data = $document['data'] ?? null;
        if ($resource === null || !\is_array($data) || !\is_array($data['relationships'] ?? null)) {
            return [$instance, $body];
        }

        $relationships = $data['relationships'];
        foreach (\array_keys($relationships) as $name) {
            $relation = $resource->relationNamed((string) $name);
            if ($relation === null || !$relation->isToMany() || !$relation instanceof GuardsMembershipInterface
                || $relation->isReadOnlyFor(false, $body) || !$body->hasToManyRelationship($relation->name())
                || $relation->allowsMembershipChangeFor($body, $instance)) {
                continue;
            }

            $filled = $relation->hydrateRelationship($instance, $body->getToManyRelationship($relation->name()));
            \assert(\is_object($filled));
            $instance = $filled;
            unset($relationships[$name]);
        }

        if (\count($relationships) === \count($data['relationships'])) {
            return [$instance, $body];
        }

        $data['relationships'] = $relationships;
        $document['data'] = $data;
        $stripped = $body->withParsedBody($document);
        \assert($stripped instanceof JsonApiRequestInterface);

        return [$instance, $stripped];
    }

    /**
     * Sets each relationship the input document names to the related models, as a write
     * of the type holds them, and returns the document without its relationships. Core's
     * hydration would assign the bare ids, which a property holding a collection or an
     * entity refuses. A relation written by its `fillUsing()` hook still gets the linkage,
     * and a to-many that can't change its members is checked first against the members
     * `$stored` holds, the resource the action was called on, or the blank input when it is
     * `null`. A member that doesn't exist is a `404`.
     *
     * @return array{object, JsonApiRequestInterface}
     */
    private function relateInput(Server $server, AbstractResource $resource, object $instance, JsonApiRequestInterface $body, ?object $stored): array
    {
        $this->linkageGuard?->forget();

        /** @var array<string, mixed> $document */
        $document = (array) $body->getParsedBody();
        $data = $document['data'] ?? null;
        if (!\is_array($data) || !\is_array($data['relationships'] ?? null) || $data['relationships'] === []) {
            return [$instance, $body];
        }

        $creating = $body->getMethod() === 'POST';
        foreach (\array_keys($data['relationships']) as $name) {
            $relation = $resource->relationNamed((string) $name);
            if ($relation === null || $relation->isReadOnlyFor($creating, $body)) {
                continue;
            }

            $linkage = $relation->isToMany()
                ? ($body->hasToManyRelationship($relation->name()) ? $body->getToManyRelationship($relation->name()) : null)
                : ($body->hasToOneRelationship($relation->name()) ? $body->getToOneRelationship($relation->name()) : null);
            if ($linkage === null) {
                continue;
            }

            if (!$creating && $linkage instanceof ToManyRelationship && $relation instanceof GuardsMembershipInterface
                && !$relation->allowsMembershipChangeFor($body, $stored ?? $instance)) {
                $this->guardMembership($server, $resource, $relation, $linkage, $body, $stored ?? $instance, $stored !== null);
            }

            if (RelationFillHook::declaredOn($relation)) {
                $filled = $relation->hydrateRelationship($instance, $linkage);
                \assert(\is_object($filled));
                $instance = $filled;

                continue;
            }

            $property = $relation->column() ?? $relation->name();
            if ($linkage instanceof ToOneRelationship) {
                $identifier = $linkage->resourceIdentifier;
                Accessor::set($instance, $property, $identifier?->id === null ? null : $this->related($identifier->type, $identifier->id));

                continue;
            }

            $members = [];
            foreach ($linkage->resourceIdentifiers as $identifier) {
                if ($identifier->id !== null) {
                    $members[] = $this->related($identifier->type, $identifier->id);
                }
            }
            // A foreign-key to-many holds nothing on the model, so an input with nowhere to put
            // it leaves its linkage to the handler rather than gain a dynamic property.
            if (ForeignKey::nameOf($relation) !== null && !self::holds($instance, $property)) {
                continue;
            }
            $held = Accessor::get($instance, $property);
            if ($held instanceof Collection) {
                $held->clear();
                foreach ($members as $member) {
                    $held->add($member);
                }
            } else {
                Accessor::set($instance, $property, $members);
            }
        }

        unset($data['relationships']);
        $document['data'] = $data;
        $stripped = $body->withParsedBody($document);
        \assert($stripped instanceof JsonApiRequestInterface);

        return [$instance, $stripped];
    }

    /**
     * Whether `$instance` takes a value for `$property`: an array access, a declared property
     * or a setter.
     */
    private static function holds(object $instance, string $property): bool
    {
        return $instance instanceof \ArrayAccess
            || \property_exists($instance, $property)
            || \method_exists($instance, 'set' . \ucfirst($property));
    }

    /**
     * Refuses a replacement that changes the members of a to-many declaring
     * `cannotChangeMembership()`, read as an update of the resource reads them: through the
     * foreign key for a relation read that way, and with the members the related type's
     * scope hides kept, so a client restating the members it can see changes nothing. The
     * blank input of an action called on no resource holds none.
     *
     * @throws \haddowg\JsonApi\Exception\MembershipChangeProhibited
     */
    private function guardMembership(Server $server, AbstractResource $resource, RelationInterface&GuardsMembershipInterface $relation, ToManyRelationship $linkage, JsonApiRequestInterface $body, object $current, bool $stored): void
    {
        if (!$stored) {
            $relation->assertMembershipUnchanged($body, $current, CurrentMembers::of($server, $this->providers, $current, $relation, $body), $linkage);

            return;
        }

        $scoped = $this->linkageGuard?->scope($server, $current, $relation, $linkage, Mode::Replace, $body) ?? $linkage;
        \assert($scoped instanceof ToManyRelationship);
        $members = $this->readRouter?->readsByForeignKey($relation) === true
            ? $this->readRouter->foreignKeyMembers($server, $resource::$type, $current, $relation, $body)
            : CurrentMembers::of($server, $this->providers, $current, $relation, $body);
        $relation->assertMembershipUnchanged($body, $current, $members, $scoped);
    }

    private function related(string $type, string $id): object
    {
        if (!$this->providers->supportsType($type)) {
            throw new ResourceNotFound();
        }

        return $this->providers->forType($type)->fetchOne($type, $id) ?? throw new ResourceNotFound();
    }

    /**
     * Runs the Symfony Validator bridge over the action's request document against
     * the `inputType`'s constraints, when one is wired (it is optional) and the
     * `inputType` has a resource declaring constraints. A bare serializer/hydrator pair
     * declares none, so there is nothing to validate — exactly the CrudOperationHandler
     * idiom. A `PATCH` validates in the update context and anything else in the create
     * context, as the hydrator picks.
     */
    private function validate(ResolvingServerInterface $server, string $inputType, JsonApiRequestInterface $body): void
    {
        if (!$server instanceof Server) {
            return;
        }

        $creating = $body->getMethod() !== 'PATCH';
        $resource = $this->types->resourceFor($server, $inputType);
        ReadOnlyAttributeGuard::check($server, $resource, $body, creating: $creating);
        if ($resource === null) {
            return;
        }

        if ($this->validator === null) {
            // Without symfony/validator only the constraint rules go unchecked.
            $this->wireTypes->validate($resource, $body, $creating);

            return;
        }

        $this->validator->validate($resource, $body, creating: $creating);
    }

    /**
     * The current JSON:API request: the action's parsed body when present (Document
     * mode), else the originating PSR-7 request off the operation context (always a
     * {@see JsonApiRequestInterface} on the bundle's request path).
     */
    private function request(CustomActionOperation $operation): JsonApiRequestInterface
    {
        $body = $operation->body();
        if ($body !== null) {
            return $body;
        }

        $request = $operation->context()->httpRequest();
        \assert($request instanceof JsonApiRequestInterface);

        return $request;
    }

    /**
     * The name of the server the action dispatched on, read from the
     * `_jsonapi_server` request attribute the {@see \haddowg\JsonApiBundle\EventListener\RequestListener}
     * carries through, defaulting to the implicit `default` server — the same
     * mechanism the CrudOperationHandler resolves the server name with, so the
     * registry's composite key matches the route loader's per-server descriptors.
     */
    private function serverName(CustomActionOperation $operation): string
    {
        $request = $operation->body() ?? $operation->context()->httpRequest();
        $name = $request?->getAttribute('_jsonapi_server');

        return \is_string($name) && $name !== '' ? $name : ServerProvider::DEFAULT_SERVER;
    }

    private function dispatch(object $event): void
    {
        $this->dispatcher?->dispatch($event);
    }
}
