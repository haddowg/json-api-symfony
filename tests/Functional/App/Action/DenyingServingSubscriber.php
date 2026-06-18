<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApiBundle\Event\ServingEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * A request-wide serving-gate witness for the custom-action suite (bundle ADR 0042 /
 * design §6): it subscribes to the {@see ServingEvent} core fires once per dispatch
 * inside `Server::dispatch()` — which a {@see \haddowg\JsonApi\Operation\CustomActionOperation}
 * routes through unchanged, so the gate covers actions for free — and **denies** the
 * request (a `403` via the exception listener) when the request carries the
 * `X-Deny-Serving` header. Without that header it is inert, so every other action
 * test dispatches normally. The throw propagates out of the serving closure, out of
 * `dispatch()`, to the route-scoped exception listener.
 */
final class DenyingServingSubscriber implements EventSubscriberInterface
{
    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [ServingEvent::class => 'onServing'];
    }

    public function onServing(ServingEvent $event): void
    {
        if ($event->request->getHeaderLine('X-Deny-Serving') !== '') {
            throw new AccessDeniedException('The serving gate denied this request.');
        }
    }
}
