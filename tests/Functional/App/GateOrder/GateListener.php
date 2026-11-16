<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\GateOrder;

use haddowg\JsonApiBundle\Event\AfterFetchOneEvent;
use haddowg\JsonApiBundle\Event\BeforeActionEvent;
use haddowg\JsonApiBundle\Event\BeforeApplyUpdateEvent;
use haddowg\JsonApiBundle\Event\BeforeCreateEvent;
use haddowg\JsonApiBundle\Event\BeforeDeleteEvent;
use haddowg\JsonApiBundle\Event\BeforeFetchCollectionEvent;
use haddowg\JsonApiBundle\Event\BeforeFetchRelatedEvent;
use haddowg\JsonApiBundle\Event\BeforeFetchRelationshipEvent;
use haddowg\JsonApiBundle\Event\BeforeMemberMoveEvent;
use haddowg\JsonApiBundle\Event\BeforeRelationshipCommitEvent;
use haddowg\JsonApiBundle\Event\BeforeRelationshipMutateEvent;
use haddowg\JsonApiBundle\Event\BeforeSaveEvent;
use haddowg\JsonApiBundle\Event\BeforeUpdateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * An application listener at the default priority on every event a security gate shares,
 * recording each `gateWidgets` event it sees.
 */
final class GateListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return \array_fill_keys([
            BeforeSaveEvent::class,
            BeforeCreateEvent::class,
            BeforeApplyUpdateEvent::class,
            BeforeUpdateEvent::class,
            BeforeDeleteEvent::class,
            BeforeRelationshipMutateEvent::class,
            BeforeRelationshipCommitEvent::class,
            BeforeMemberMoveEvent::class,
            AfterFetchOneEvent::class,
            BeforeFetchCollectionEvent::class,
            BeforeFetchRelatedEvent::class,
            BeforeFetchRelationshipEvent::class,
            BeforeActionEvent::class,
        ], 'on');
    }

    public function on(object $event): void
    {
        if (\property_exists($event, 'type') && $event->{'type'} === 'gateWidgets') {
            GateLog::$entries[] = (new \ReflectionClass($event))->getShortName();
        }
    }
}
