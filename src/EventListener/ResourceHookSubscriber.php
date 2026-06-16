<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\EventListener;

use haddowg\JsonApiBundle\Event\AfterCreateEvent;
use haddowg\JsonApiBundle\Event\AfterDeleteEvent;
use haddowg\JsonApiBundle\Event\AfterFetchCollectionEvent;
use haddowg\JsonApiBundle\Event\AfterFetchOneEvent;
use haddowg\JsonApiBundle\Event\AfterRelationshipMutateEvent;
use haddowg\JsonApiBundle\Event\AfterSaveEvent;
use haddowg\JsonApiBundle\Event\AfterUpdateEvent;
use haddowg\JsonApiBundle\Event\BeforeCreateEvent;
use haddowg\JsonApiBundle\Event\BeforeDeleteEvent;
use haddowg\JsonApiBundle\Event\BeforeRelationshipMutateEvent;
use haddowg\JsonApiBundle\Event\BeforeSaveEvent;
use haddowg\JsonApiBundle\Event\BeforeUpdateEvent;
use haddowg\JsonApiBundle\Hook\HookContext;
use haddowg\JsonApiBundle\Hook\ResourceLifecycleHooksInterface;
use haddowg\JsonApiBundle\Server\ServerProvider;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The built-in subscriber that makes the per-type resource hook methods
 * ({@see ResourceLifecycleHooksInterface}) sugar over the lifecycle events: it
 * listens to every event the {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler}
 * (and the serving bridge) fires, resolves the resource for the event's type, and
 * — when that resource implements {@see ResourceLifecycleHooksInterface} — calls
 * the matching method, passing the entity plus an assembled {@see HookContext}.
 *
 * So an application has **two** equivalent ways to hook the lifecycle: register
 * its own subscriber on the event classes (a cross-cutting concern), or implement
 * the interface on a resource (a per-type concern) — both run from the one
 * dispatch point.
 *
 * It is a no-op for a type whose resource does not implement the interface, and
 * for a bare serializer/hydrator pair (no resource). A before-hook method that
 * throws a {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} propagates
 * (aborting the operation); an after-hook method that returns a response replaces
 * the event's response (which the handler reads back). The `ServingEvent` is
 * **not** handled here — it is a server-level (not per-type) seam with no resource
 * to route to.
 */
final class ResourceHookSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ServerProvider $servers,
        private readonly TypeMetadataResolver $types,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            BeforeSaveEvent::class => 'onBeforeSave',
            AfterSaveEvent::class => 'onAfterSave',
            BeforeCreateEvent::class => 'onBeforeCreate',
            AfterCreateEvent::class => 'onAfterCreate',
            BeforeUpdateEvent::class => 'onBeforeUpdate',
            AfterUpdateEvent::class => 'onAfterUpdate',
            BeforeDeleteEvent::class => 'onBeforeDelete',
            AfterDeleteEvent::class => 'onAfterDelete',
            BeforeRelationshipMutateEvent::class => 'onBeforeRelationshipMutate',
            AfterRelationshipMutateEvent::class => 'onAfterRelationshipMutate',
            AfterFetchOneEvent::class => 'onAfterFetchOne',
            AfterFetchCollectionEvent::class => 'onAfterFetchCollection',
        ];
    }

    public function onBeforeSave(BeforeSaveEvent $event): void
    {
        $this->hooks($event->serverName, $event->type)
            ?->beforeSave($event->entity, $event->creating, $this->context($event->serverName, $event->type, $event->request));
    }

    public function onAfterSave(AfterSaveEvent $event): void
    {
        $response = $this->hooks($event->serverName, $event->type)
            ?->afterSave($event->entity, $event->creating, $this->context($event->serverName, $event->type, $event->request));
        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    public function onBeforeCreate(BeforeCreateEvent $event): void
    {
        $this->hooks($event->serverName, $event->type)
            ?->beforeCreate($event->entity, $this->context($event->serverName, $event->type, $event->request));
    }

    public function onAfterCreate(AfterCreateEvent $event): void
    {
        $response = $this->hooks($event->serverName, $event->type)
            ?->afterCreate($event->entity, $this->context($event->serverName, $event->type, $event->request));
        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    public function onBeforeUpdate(BeforeUpdateEvent $event): void
    {
        $this->hooks($event->serverName, $event->type)
            ?->beforeUpdate($event->entity, $event->original, $this->context($event->serverName, $event->type, $event->request));
    }

    public function onAfterUpdate(AfterUpdateEvent $event): void
    {
        $response = $this->hooks($event->serverName, $event->type)
            ?->afterUpdate($event->entity, $this->context($event->serverName, $event->type, $event->request));
        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    public function onBeforeDelete(BeforeDeleteEvent $event): void
    {
        $this->hooks($event->serverName, $event->type)
            ?->beforeDelete($event->entity, $this->context($event->serverName, $event->type, $event->request));
    }

    public function onAfterDelete(AfterDeleteEvent $event): void
    {
        $response = $this->hooks($event->serverName, $event->type)
            ?->afterDelete($event->entity, $this->context($event->serverName, $event->type, $event->request));
        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    public function onBeforeRelationshipMutate(BeforeRelationshipMutateEvent $event): void
    {
        $this->hooks($event->serverName, $event->type)
            ?->beforeRelationshipMutate(
                $event->parent,
                $this->relationshipContext($event->serverName, $event->type, $event->request, $event->relation, $event->linkage, $event->mode),
            );
    }

    public function onAfterRelationshipMutate(AfterRelationshipMutateEvent $event): void
    {
        $response = $this->hooks($event->serverName, $event->type)
            ?->afterRelationshipMutate(
                $event->parent,
                $this->relationshipContext($event->serverName, $event->type, $event->request, $event->relation, $event->linkage, $event->mode),
            );
        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    public function onAfterFetchOne(AfterFetchOneEvent $event): void
    {
        $response = $this->hooks($event->serverName, $event->type)
            ?->afterFetchOne($event->entity, $this->context($event->serverName, $event->type, $event->request));
        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    public function onAfterFetchCollection(AfterFetchCollectionEvent $event): void
    {
        $response = $this->hooks($event->serverName, $event->type)
            ?->afterFetchCollection($event->items, $this->context($event->serverName, $event->type, $event->request));
        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    /**
     * The hook-implementing resource for `$type` on `$serverName`, or `null` when
     * the type has no resource (a bare pair) or its resource does not opt into the
     * hook interface.
     */
    private function hooks(string $serverName, string $type): ?ResourceLifecycleHooksInterface
    {
        $server = $this->servers->get($serverName);
        $resource = $this->types->resourceFor($server, $type);

        return $resource instanceof ResourceLifecycleHooksInterface ? $resource : null;
    }

    private function context(
        string $serverName,
        string $type,
        \haddowg\JsonApi\Request\JsonApiRequestInterface $request,
    ): HookContext {
        return new HookContext($request, $serverName, $type);
    }

    private function relationshipContext(
        string $serverName,
        string $type,
        \haddowg\JsonApi\Request\JsonApiRequestInterface $request,
        \haddowg\JsonApi\Resource\Field\RelationInterface $relation,
        object $linkage,
        \haddowg\JsonApi\Resource\Field\Mode $mode,
    ): HookContext {
        return new HookContext($request, $serverName, $type, $relation, $linkage, $mode);
    }
}
