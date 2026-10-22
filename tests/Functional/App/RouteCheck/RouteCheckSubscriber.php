<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RouteCheck;

use haddowg\JsonApiBundle\Routing\JsonApiRoute;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Asks {@see JsonApiRoute::matches()} on either side of the RouterListener (priority 32)
 * and reports both answers as response headers.
 */
final class RouteCheckSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['beforeRouting', 64], ['afterRouting', 20]],
            KernelEvents::RESPONSE => 'report',
        ];
    }

    public function beforeRouting(RequestEvent $event): void
    {
        $event->getRequest()->attributes->set('_test_before_routing', JsonApiRoute::matches($event->getRequest()));
    }

    public function afterRouting(RequestEvent $event): void
    {
        $event->getRequest()->attributes->set('_test_after_routing', JsonApiRoute::matches($event->getRequest()));
    }

    public function report(ResponseEvent $event): void
    {
        $attributes = $event->getRequest()->attributes;
        $headers = $event->getResponse()->headers;
        $headers->set('X-Before-Routing', $attributes->get('_test_before_routing') === true ? 'yes' : 'no');
        $headers->set('X-After-Routing', $attributes->get('_test_after_routing') === true ? 'yes' : 'no');
    }
}
