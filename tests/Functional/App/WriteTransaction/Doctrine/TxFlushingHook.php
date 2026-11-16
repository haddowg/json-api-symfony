<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApi\Exception\AccessDenied;
use haddowg\JsonApiBundle\Event\BeforeCreateEvent;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\TxScenario;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * A before-create hook that persists the new widget or tag and flushes, as an application
 * does to read a generated id, and then throws when the scenario says so.
 */
final readonly class TxFlushingHook implements EventSubscriberInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public static function getSubscribedEvents(): array
    {
        return [BeforeCreateEvent::class => 'onBeforeCreate'];
    }

    public function onBeforeCreate(BeforeCreateEvent $event): void
    {
        if (!TxScenario::$persistOnCreate) {
            return;
        }

        $this->entityManager->persist($event->entity);
        if (TxScenario::$flushOnCreate) {
            $this->entityManager->flush();
        }
        if (TxScenario::$throwAfterFlush) {
            throw new AccessDenied('Refused by the test hook after it flushed.');
        }
    }
}
