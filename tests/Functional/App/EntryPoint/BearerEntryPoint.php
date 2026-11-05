<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EntryPoint;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Challenges for a bearer token, as an OAuth resource server does.
 */
final class BearerEntryPoint implements AuthenticationEntryPointInterface
{
    public const string CHALLENGE = 'Bearer realm="api", resource_metadata="https://example.test/.well-known/oauth-protected-resource"';

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new Response('Sign in with a bearer token', 401, [
            'WWW-Authenticate' => self::CHALLENGE,
            'Content-Type' => 'text/plain',
            'X-Entry-Point' => 'bearer',
        ]);
    }
}
