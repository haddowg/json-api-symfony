<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessToken\AccessTokenExtractorInterface;

/**
 * Pulls the raw value out of an `Authorization: Bearer <token>` header for the
 * example's stateless `access_token` firewall.
 *
 * Symfony's default {@see \Symfony\Component\Security\Http\AccessToken\HeaderAccessTokenExtractor}
 * enforces the RFC 6750 `b64token` grammar, which excludes `@` — but this example's
 * token *is* the user identifier, and the seeded users are identified by email
 * (`ada@example.com`). A real app whose opaque tokens are not strict `b64token`s
 * supplies its own extractor exactly like this; here it simply trims the `Bearer `
 * prefix and hands the rest to the {@see AccessTokenHandler} verbatim.
 */
final class BearerTokenExtractor implements AccessTokenExtractorInterface
{
    public function extractAccessToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');
        if (!\is_string($header) || !\str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = \trim(\substr($header, \strlen('Bearer ')));

        return $token === '' ? null : $token;
    }
}
