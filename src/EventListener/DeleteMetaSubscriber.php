<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\EventListener;

use haddowg\JsonApiBundle\Event\BeforeDeleteEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates a delete's meta-only body at {@see self::PRIORITY}: below the security gate,
 * so a caller the gate refuses gets its `403` whatever the body holds, and above the
 * default `0`, so the resource's `beforeDelete()` hook and other listeners read the typed
 * meta.
 */
final class DeleteMetaSubscriber implements EventSubscriberInterface
{
    /**
     * Between {@see \haddowg\JsonApiBundle\Security\ResourceSecuritySubscriber::PRIORITY} and `0`.
     */
    public const PRIORITY = 128;

    public static function getSubscribedEvents(): array
    {
        return [BeforeDeleteEvent::class => ['onBeforeDelete', self::PRIORITY]];
    }

    public function onBeforeDelete(BeforeDeleteEvent $event): void
    {
        $event->validateMeta();
    }
}
