<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Serve;

use haddowg\JsonApi\OpenApi\Serve\EntityTag;
use haddowg\JsonApiBundle\OpenApi\OpenApiServeCacheKeyInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ETag, `If-None-Match` and `Cache-Control` handling shared by the `docs.json` and
 * `schemas.json` controllers (bundle ADR 0131).
 *
 * @internal
 */
final class ConditionalResponder
{
    public const int JSON_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT;

    public function __construct(private readonly ?string $cacheControl = null) {}

    /**
     * Applies the serve decorators' priority order: the tagged iterator yields the highest
     * priority first, and the highest must run last.
     *
     * @template T of object
     *
     * @param iterable<T> $decorators
     *
     * @return list<T>
     */
    public static function order(iterable $decorators): array
    {
        $ordered = \is_array($decorators) ? \array_values($decorators) : \iterator_to_array($decorators, false);

        return \array_reverse($ordered);
    }

    /**
     * Whether every decorator supplies a cache key. A partial key would let two different
     * bodies share an ETag, so anything short of all of them falls back to the body hash.
     *
     * @param list<object> $decorators
     */
    public static function allKeyed(array $decorators): bool
    {
        foreach ($decorators as $decorator) {
            if (!$decorator instanceof OpenApiServeCacheKeyInterface) {
                return false;
            }
        }

        return $decorators !== [];
    }

    /**
     * The ETag hash of a decorated response, from the artifact hash and every decorator's
     * cache key in decorator order.
     *
     * @param list<OpenApiServeCacheKeyInterface> $decorators
     */
    public static function keyedHash(string $artifactHash, array $decorators, Request $request, string $server): string
    {
        $keys = [];
        foreach ($decorators as $decorator) {
            // The class name catches a decorator added, removed or reordered between deploys.
            $keys[] = [$decorator::class, $decorator->cacheKey($request, $server)];
        }

        return EntityTag::keyed($artifactHash, $keys);
    }

    /**
     * A JSON response carrying the ETag and the configured `Cache-Control`, still without
     * a body.
     */
    public function prepare(string $hash): Response
    {
        $response = new Response(null, Response::HTTP_OK, ['Content-Type' => 'application/json']);
        $response->setEtag($hash);
        if ($this->cacheControl !== null) {
            $response->headers->set('Cache-Control', $this->cacheControl);
        }

        return $response;
    }

    /**
     * Whether `If-None-Match` matches the response's ETag, turning the response into a 304
     * when it does. The comparison is weak, so a CDN's `W/` copy of the tag still matches.
     */
    public function notModified(Response $response, Request $request): bool
    {
        $etag = $response->getEtag();
        if (!$request->isMethodCacheable() || $etag === null || !EntityTag::matches($etag, $request->headers->get('If-None-Match'))) {
            return false;
        }

        $response->setNotModified();

        return true;
    }
}
