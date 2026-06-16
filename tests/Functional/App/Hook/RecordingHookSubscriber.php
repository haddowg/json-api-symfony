<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Hook;

use haddowg\JsonApi\Response\DataResponse;
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
use haddowg\JsonApiBundle\Event\ServingEvent;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The cross-cutting application subscriber the lifecycle-hooks suite uses to prove
 * the **event** mechanism: it listens to the {@see ServingEvent} and every
 * per-operation event the {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler}
 * fires, recording the firing order into {@see HookLog}. It only acts for the
 * event-path type (`hookWidgets`), leaving the resource-method-path type
 * (`hookableWidgets`) to {@see HookableWidgetResource}, so the two mechanisms are
 * observed independently in one kernel.
 *
 * Driven by {@see HookLog}'s control flags: a before-event throws to abort (so the
 * suite asserts the operation never commits and the thrown status renders), an
 * after-event replaces the response with one carrying a `replacedBy` meta marker
 * (so the suite asserts custom-action shaping). The serving event is the only one
 * not scoped to a type, so it records unconditionally and may abort before any
 * operation runs.
 */
final class RecordingHookSubscriber implements EventSubscriberInterface
{
    private const string TYPE = 'hookWidgets';

    public function __construct(private readonly ServerProvider $servers) {}

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ServingEvent::class => 'onServing',
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

    public function onServing(ServingEvent $event): void
    {
        HookLog::record('serving');
        HookLog::maybeThrow('serving');
    }

    public function onBeforeSave(BeforeSaveEvent $event): void
    {
        if ($event->type !== self::TYPE) {
            return;
        }

        HookLog::record('beforeSave');
        HookLog::maybeThrow('beforeSave');
    }

    public function onAfterSave(AfterSaveEvent $event): void
    {
        if ($event->type !== self::TYPE) {
            return;
        }

        HookLog::record('afterSave');
        if (HookLog::shouldReplace('afterSave')) {
            $event->setResponse($this->replacement($event->type, $event->entity, 'afterSave'));
        }
    }

    public function onBeforeCreate(BeforeCreateEvent $event): void
    {
        if ($event->type !== self::TYPE) {
            return;
        }

        // A before-create mutation: stamp the entity so a follow-up read proves the
        // mutation was persisted (the entity is flushed after this hook).
        \assert($event->entity instanceof HookWidget);
        $event->entity->stamp = 'subscriber-stamped';

        HookLog::record('beforeCreate');
        HookLog::maybeThrow('beforeCreate');
    }

    public function onAfterCreate(AfterCreateEvent $event): void
    {
        if ($event->type !== self::TYPE) {
            return;
        }

        HookLog::record('afterCreate');
        if (HookLog::shouldReplace('afterCreate')) {
            $event->setResponse($this->replacement($event->type, $event->entity, 'afterCreate'));
        }
    }

    public function onBeforeUpdate(BeforeUpdateEvent $event): void
    {
        if ($event->type !== self::TYPE) {
            return;
        }

        // The entity is the post-hydration incoming change; the original is the
        // pre-change snapshot the handler cloned before hydration.
        \assert($event->entity instanceof HookWidget);
        \assert($event->original instanceof HookWidget);
        HookLog::recordUpdateDiff($event->original->name, $event->entity->name);
        HookLog::maybeThrow('beforeUpdate');
    }

    public function onAfterUpdate(AfterUpdateEvent $event): void
    {
        if ($event->type !== self::TYPE) {
            return;
        }

        HookLog::record('afterUpdate');
        if (HookLog::shouldReplace('afterUpdate')) {
            $event->setResponse($this->replacement($event->type, $event->entity, 'afterUpdate'));
        }
    }

    public function onBeforeDelete(BeforeDeleteEvent $event): void
    {
        if ($event->type !== self::TYPE) {
            return;
        }

        HookLog::record('beforeDelete');
        HookLog::maybeThrow('beforeDelete');
    }

    public function onAfterDelete(AfterDeleteEvent $event): void
    {
        if ($event->type !== self::TYPE) {
            return;
        }

        HookLog::record('afterDelete');
    }

    public function onBeforeRelationshipMutate(BeforeRelationshipMutateEvent $event): void
    {
        if ($event->type !== self::TYPE) {
            return;
        }

        HookLog::record('beforeRelationshipMutate');
        HookLog::maybeThrow('beforeRelationshipMutate');
    }

    public function onAfterRelationshipMutate(AfterRelationshipMutateEvent $event): void
    {
        if ($event->type !== self::TYPE) {
            return;
        }

        HookLog::record('afterRelationshipMutate');
    }

    public function onAfterFetchOne(AfterFetchOneEvent $event): void
    {
        if ($event->type !== self::TYPE) {
            return;
        }

        HookLog::record('afterFetchOne');
        if (HookLog::shouldReplace('afterFetchOne')) {
            $event->setResponse($this->replacement($event->type, $event->entity, 'afterFetchOne'));
        }
    }

    public function onAfterFetchCollection(AfterFetchCollectionEvent $event): void
    {
        if ($event->type !== self::TYPE) {
            return;
        }

        HookLog::record('afterFetchCollection');
    }

    /**
     * Builds a replacement response from the entity through the type's registered
     * serializer, tagged with a `replacedBy` document-meta marker the suite asserts.
     */
    private function replacement(string $type, object $entity, string $hook): DataResponse
    {
        $serializer = $this->servers->get()->serializerFor($type);

        return DataResponse::fromResource($entity, $serializer)->withMeta(['replacedBy' => $hook]);
    }
}
