<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction;

use haddowg\JsonApi\Exception\AccessDenied;
use haddowg\JsonApiBundle\Event\AfterActionEvent;
use haddowg\JsonApiBundle\Event\AfterCreateEvent;
use haddowg\JsonApiBundle\Event\AfterDeleteEvent;
use haddowg\JsonApiBundle\Event\AfterRelationshipMutateEvent;
use haddowg\JsonApiBundle\Event\AfterSaveEvent;
use haddowg\JsonApiBundle\Event\AfterUpdateEvent;
use haddowg\JsonApiBundle\Event\BeforeActionEvent;
use haddowg\JsonApiBundle\Event\BeforeApplyUpdateEvent;
use haddowg\JsonApiBundle\Event\BeforeCreateEvent;
use haddowg\JsonApiBundle\Event\BeforeDeleteEvent;
use haddowg\JsonApiBundle\Event\BeforeRelationshipCommitEvent;
use haddowg\JsonApiBundle\Event\BeforeRelationshipMutateEvent;
use haddowg\JsonApiBundle\Event\BeforeSaveEvent;
use haddowg\JsonApiBundle\Event\BeforeUpdateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Acts out {@see TxScenario} on the `txWidgets` lifecycle events.
 */
final readonly class TxHookSubscriber implements EventSubscriberInterface
{
    public function __construct(private TxSideWriter $writer) {}

    public static function getSubscribedEvents(): array
    {
        return \array_fill_keys([
            BeforeApplyUpdateEvent::class,
            BeforeSaveEvent::class,
            BeforeCreateEvent::class,
            BeforeUpdateEvent::class,
            BeforeDeleteEvent::class,
            BeforeRelationshipMutateEvent::class,
            BeforeRelationshipCommitEvent::class,
            BeforeActionEvent::class,
            AfterActionEvent::class,
            AfterCreateEvent::class,
            AfterUpdateEvent::class,
            AfterSaveEvent::class,
            AfterDeleteEvent::class,
            AfterRelationshipMutateEvent::class,
        ], 'on');
    }

    public function on(object $event): void
    {
        if ((\property_exists($event, 'type') ? $event->{'type'} : null) !== 'txWidgets') {
            return;
        }

        $class = $event::class;
        if (TxScenario::$nestOn === $class) {
            $this->writer->begin();
        }
        if (TxScenario::$sideWriteOn === $class) {
            $this->writer->write('by ' . (new \ReflectionClass($event))->getShortName());
        }
        if (TxScenario::$unnestOn === $class) {
            $this->writer->commit();
        }
        if (TxScenario::$throwOn === $class && $this->targets($event)) {
            throw new AccessDenied('Refused by the test hook.');
        }
    }

    private function targets(object $event): bool
    {
        if (TxScenario::$throwForName === null) {
            return true;
        }

        $entity = \property_exists($event, 'entity') ? $event->{'entity'} : null;

        return $entity instanceof TxWidget && $entity->name === TxScenario::$throwForName;
    }
}
