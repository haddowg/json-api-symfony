<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Serve;

use haddowg\JsonApiBundle\OpenApi\ArtifactStore;
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

        return ArtifactStore::hash($artifactHash . "\n" . \json_encode($keys, self::JSON_FLAGS & ~\JSON_PRETTY_PRINT));
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
     * when it does. The comparison is weak (RFC 9110 section 13.1.2): a CDN that compresses
     * the body hands the client a `W/` copy of the ETag, and that must still match.
     */
    public function notModified(Response $response, Request $request): bool
    {
        if (!$request->isMethodCacheable()) {
            return false;
        }

        $etag = $response->getEtag();
        if ($etag === null) {
            return false;
        }
        $etag = self::opaque($etag);

        foreach ($request->getETags() as $candidate) {
            $candidate = \trim($candidate);
            if ($candidate === '*' || self::opaque($candidate) === $etag) {
                $response->setNotModified();

                return true;
            }
        }

        return false;
    }

    private static function opaque(string $etag): string
    {
        return \str_starts_with($etag, 'W/') ? \substr($etag, 2) : $etag;
    }
}
