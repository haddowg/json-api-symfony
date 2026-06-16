<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Security;

use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Resolves a Bearer access token to the seeded user behind the example's stateless
 * `access_token` firewall (the most common API auth scenario). The smallest possible
 * witness: the token **is** the user identifier, so the badge carries it straight to
 * the in-memory user provider — which loads the matching `InMemoryUser`.
 *
 * A real application would map an *opaque* token to a user (a lookup against a token
 * store, or a signed/JWT claim) here, rather than treating the token as the
 * identifier. This is the only place that mapping lives.
 */
final class AccessTokenHandler implements AccessTokenHandlerInterface
{
    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        // The token is the user identifier; the configured user provider loads it.
        return new UserBadge($accessToken);
    }
}
