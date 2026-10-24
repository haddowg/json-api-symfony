<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Reads the security token on every request, as an app's locale or feature listener
 * would. Under a stateful firewall that marks the session as used, which is what makes
 * Symfony's session listener rewrite a response to `private`.
 */
final class TokenReadingListener implements EventSubscriberInterface
{
    public function __construct(private readonly TokenStorageInterface $tokenStorage) {}

    public static function getSubscribedEvents(): array
    {
        // After the firewall (priority 8), so the usage-tracking storage is in place.
        return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $this->tokenStorage->getToken();
    }
}
