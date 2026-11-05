<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EntryPoint;

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Throws an {@see UnauthorizedHttpException} on a request carrying the trigger header,
 * after routing and the firewall have run.
 */
final class UnauthorizedHttpExceptionListener
{
    public const string TRIGGER = 'HTTP_X_THROW_UNAUTHORIZED';

    public const string CHALLENGE = 'Bearer realm="legacy", error="invalid_token"';

    public function __invoke(RequestEvent $event): void
    {
        if ($event->getRequest()->server->has(self::TRIGGER)) {
            throw new UnauthorizedHttpException(self::CHALLENGE, 'token revoked');
        }
    }
}
