<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\EventListener;

use haddowg\JsonApiBundle\Http\ResponseHeaderOperation;
use haddowg\JsonApiBundle\Http\ResponseHeadersRegistry;
use haddowg\JsonApiBundle\Operation\TargetResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * The route-scoped `kernel.response` listener that emits the declarative response
 * headers a JSON:API type declares (bundle ADR 0054):
 *
 *  - **HTTP cache headers (gap G7)** — the resolved {@see \haddowg\JsonApiBundle\Http\CacheHeaders}
 *    (resource-level + per-operation override, layered over the global
 *    `json_api.defaults.cache_headers`) on a **safe (`GET`) successful** read only.
 *    A write (`POST`/`PATCH`/`DELETE`) or an error document never gets a
 *    `Cache-Control` — caching a write or an error is wrong, so both are skipped.
 *  - **deprecation + sunset (gap G16)** — `Deprecation` (IETF Deprecation-header draft) + `Sunset` (RFC 8594) — the resolved
 *    {@see \haddowg\JsonApiBundle\Http\DeprecationHeaders} on **every** response for
 *    the type (reads and writes alike — a deprecated endpoint is deprecated
 *    regardless of method).
 *
 * It acts only on the bundle's JSON:API routes (the {@see ExceptionListener::ROUTE_MARKER}
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
    ) {}

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->attributes->get(ExceptionListener::ROUTE_MARKER) !== true) {
            return;
        }

        $type = $request->attributes->get(TargetResolver::TYPE_ATTRIBUTE);
        if (!\is_string($type) || $type === '') {
            return;
        }

        $response = $event->getResponse();

        // Deprecation/Sunset are emitted on every response for the type, reads and
        // writes alike (each header is only written when the app has not set it).
        $this->registry->deprecationFor($type)?->applyTo($response);

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

        $cache = $this->registry->cacheFor($type, $this->operationFor($request));
        $cache?->applyTo($response);
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
