<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Operation;

use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Resource\SingletonContext;
use haddowg\JsonApi\Server\Server;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves the caller's id for a singleton target, once per request, so the operation that
 * follows is an ordinary fetch, update, relationship or action on that id.
 *
 * The type's `resolveSingletonId()` receives a {@see SingletonContext} carrying the PSR-7
 * request and two attributes: {@see USER_ATTRIBUTE}, the authenticated Symfony user or
 * `null`, and {@see REQUEST_ATTRIBUTE}, the HttpFoundation request. A type that names no
 * resource for the caller answers `404`.
 */
final readonly class SingletonIdResolver
{
    /**
     * The attribute carrying the authenticated user, a
     * {@see \Symfony\Component\Security\Core\User\UserInterface}, or `null` when nobody is
     * signed in or symfony/security-core isn't installed.
     */
    public const string USER_ATTRIBUTE = 'user';

    /**
     * The attribute carrying the HttpFoundation {@see Request}.
     */
    public const string REQUEST_ATTRIBUTE = 'request';

    public function __construct(
        private ?\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface $tokenStorage = null,
        private ?RequestStack $requestStack = null,
    ) {}

    /**
     * `$target` with the caller's id. A target that isn't a singleton, or already carries
     * the id, is returned as it is.
     *
     * @throws ResourceNotFound when the type names no resource for the caller
     */
    public function resolve(Server $server, Target $target, ?ServerRequestInterface $psrRequest, ?Request $request = null): Target
    {
        if (!$target->singleton || $target->id !== null) {
            return $target;
        }

        $context = new SingletonContext($target->type, $psrRequest, [
            self::USER_ATTRIBUTE => $this->tokenStorage?->getToken()?->getUser(),
            self::REQUEST_ATTRIBUTE => $request ?? $this->requestStack?->getMainRequest(),
        ]);

        $id = $server->resolveSingletonId($target->type, $context);
        if ($id === null || $id === '') {
            throw new ResourceNotFound();
        }

        return $target->withId($id);
    }
}
