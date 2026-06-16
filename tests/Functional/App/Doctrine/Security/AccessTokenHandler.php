<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security;

use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Resolves a Bearer access token to the seeded user behind the security test
 * kernel's stateless `access_token` firewall. The token IS the user identifier, so
 * the badge carries it straight to the in-memory user provider (a real app maps an
 * opaque token to a user here).
 */
final class AccessTokenHandler implements AccessTokenHandlerInterface
{
    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        return new UserBadge($accessToken);
    }
}
