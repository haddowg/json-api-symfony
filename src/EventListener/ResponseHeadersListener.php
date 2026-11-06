<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\EventListener;

use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApiBundle\Action\ActionRegistry;
use haddowg\JsonApiBundle\Http\ResponseHeaderOperation;
use haddowg\JsonApiBundle\Http\ResponseHeadersRegistry;
use haddowg\JsonApiBundle\Operation\TargetResolver;
use haddowg\JsonApiBundle\Routing\JsonApiRoute;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * The route-scoped `kernel.response` listener that emits the declarative response
 * headers a JSON:API type declares:
 *
 *  - **HTTP cache headers** — the resolved {@see \haddowg\JsonApiBundle\Http\CacheHeaders}
 *    (resource-level + per-operation override, layered over the global
 *    `json_api.defaults.cache_headers`) on a **safe (`GET`) successful** read only.
 *    A write (`POST`/`PATCH`/`DELETE`) or an error document never gets a
 *    `Cache-Control` — caching a write or an error is wrong, so both are skipped.
 *  - **deprecation + sunset** — `Deprecation` (IETF Deprecation-header draft) + `Sunset` (RFC 8594) — the resolved
 *    {@see \haddowg\JsonApiBundle\Http\DeprecationHeaders} on **every** response for
 *    the type (reads and writes alike — a deprecated endpoint is deprecated
 *    regardless of method), and on every response of an operation the type deprecates
 *    on its own or a custom action declared deprecated.
 *
 * It acts only on the bundle's JSON:API routes (the {@see JsonApiRoute::MARKER}
 * default), resolves the type + read shape the same way the request/view/exception
 * listeners do (the {@see TargetResolver} over the route attributes), and **never
 * clobbers a header an app set explicitly** (each value object checks before
 * writing, except `Cache-Control` which the app would set imperatively — see below).
 *
 * It runs on `kernel.response` (not `kernel.view`) so the final {@see \Symfony\Component\HttpFoundation\Response}
 * — built by the {@see ViewListener} or, for an error, by the {@see ExceptionListener}
 * — is in hand, and its real status code distinguishes a successful read from an
 * error without re-deriving it.
 */
final class ResponseHeadersListener
{
    public function __construct(
        private readonly ResponseHeadersRegistry $registry,
        private readonly TargetResolver $targetResolver,
        private readonly ?ActionRegistry $actions = null,
    ) {}

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!JsonApiRoute::matches($request)) {
            return;
        }

        $type = $request->attributes->get(TargetResolver::TYPE_ATTRIBUTE);
        if (!\is_string($type) || $type === '') {
            return;
        }

        // Each server emits only what its own resource class declares.
        $server = $request->attributes->get('_jsonapi_server');
        $server = \is_string($server) && $server !== '' ? $server : ServerProvider::DEFAULT_SERVER;

        $response = $event->getResponse();

        // Deprecation/Sunset are emitted on every response for the type, reads and
        // writes alike (each header is only written when the app has not set it).
        $this->registry->deprecationFor($type, $server, $this->isDeprecatedOperation($request, $type, $server))?->applyTo($response);

        // Cache headers apply only to a safe (GET) successful read — never a write
        // verb and never an error document (2xx only).
        if (!$request->isMethodCacheable() || !$response->isSuccessful()) {
            return;
        }

        // An app that configured caching itself (e.g. in an after-hook via
        // setCache()/setMaxAge()) keeps it untouched: only apply when the response
        // carries no meaningful Cache-Control. A bare HttpFoundation Response that
        // had nothing set computes the conservative `no-cache, private` default, so
        // the absence of a real directive is detected by that computed value rather
        // than has('Cache-Control') (which is always true) or a single directive.
        if ($this->hasExplicitCacheControl($response)) {
            return;
        }

        $cache = $this->registry->cacheFor($type, $this->operationFor($request), $server);
        $cache?->applyTo($response);
    }

    /**
     * Whether the request is served by an operation deprecated on its own: a CRUD,
     * related or relationship operation the type names in `deprecatedOperations`, or a
     * custom action declared `deprecated`.
     */
    private function isDeprecatedOperation(Request $request, string $type, string $server): bool
    {
        $route = JsonApiRoute::of($request);
        if ($route === null) {
            return false;
        }

        if ($route->action !== null) {
            return $route->actionScope !== null
                && ($this->actions?->descriptorFor($server, $type, $route->actionScope, $route->action)->deprecated ?? false);
        }

        $deprecated = $this->registry->deprecatedOperationsFor($type, $server);
        if ($deprecated === []) {
            return false;
        }

        $name = self::operationName($route, $request->getMethod());

        return $name !== null && \in_array($name, $deprecated, true);
    }

    /**
     * Core's operation name for the endpoint and method a request hit, the grammar
     * `deprecatedOperations` lists names in.
     */
    private static function operationName(JsonApiRoute $route, string $method): ?string
    {
        if ($route->relationship !== null) {
            $kind = $route->isRelationshipEndpoint
                ? match ($method) {
                    'GET', 'HEAD' => OperationKind::FetchRelationship,
                    'PATCH' => OperationKind::UpdateRelationship,
                    'POST' => OperationKind::AddRelationship,
                    'DELETE' => OperationKind::RemoveRelationship,
                    default => null,
                }
            : (\in_array($method, ['GET', 'HEAD'], true) ? OperationKind::FetchRelated : null);

            return $kind === null ? null : OperationKey::relation($kind, $route->relationship)->name();
        }

        $kind = $route->id !== null
            ? match ($method) {
                'GET', 'HEAD' => OperationKind::FetchOne,
                'PATCH' => OperationKind::Update,
                'DELETE' => OperationKind::Delete,
                default => null,
            }
        : match ($method) {
            'GET', 'HEAD' => OperationKind::FetchCollection,
            'POST' => OperationKind::Create,
            default => null,
        };

        return $kind === null ? null : OperationKey::crud($kind)->name();
    }

    /**
     * Whether the response already carries explicit caching the app configured —
     * any real `Cache-Control` directive (`max-age`, `s-maxage`, `public`,
     * `must-revalidate`, …) beyond the conservative `no-cache, private` /
     * `private, must-revalidate` default a bare Response computes, or an explicit
     * `Expires`/`Last-Modified` freshness signal. When so, the listener leaves the
     * response untouched.
     */
    private function hasExplicitCacheControl(Response $response): bool
    {
        if ($response->headers->has('Expires') || $response->headers->has('Last-Modified')) {
            return true;
        }

        $value = $response->headers->get('Cache-Control', '');

        return $value !== '' && $value !== 'no-cache, private';
    }

    /**
     * The read shape the request resolves to, used to pick a per-operation cache
     * override. Falls back to {@see ResponseHeaderOperation::Collection} when the
     * target cannot be resolved (it always can on a marked JSON:API route).
     */
    private function operationFor(Request $request): ResponseHeaderOperation
    {
        $target = $this->targetResolver->resolveFromRequest($request);

        return $target !== null
            ? ResponseHeaderOperation::fromTarget($target)
            : ResponseHeaderOperation::Collection;
    }
}
