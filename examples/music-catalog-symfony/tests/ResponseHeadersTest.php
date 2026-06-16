<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The declarative response-header witness (backs the README caching/deprecation
 * section; bundle ADR 0054). Two example types opt into the bundle's route-scoped
 * `ResponseHeadersListener` purely through `#[AsJsonApiResource(...)]` metadata — no
 * kernel wiring beyond the attribute:
 *
 *  - **`genres` — declarative HTTP cache headers (gap G7).** A reference/lookup type
 *    declares `cacheHeaders` (a one-hour `max_age`, a one-day CDN `s_maxage`,
 *    `public`, `Vary: Accept`) with a shorter `operations.collection` override. The
 *    listener emits `Cache-Control` + `Vary` on a successful `GET` only — the
 *    collection carries the override's `max-age=300`, a single genre the
 *    resource-level `max-age=3600`, and a write (`POST /genres`) gets no
 *    `Cache-Control` at all.
 *  - **`devices` — RFC 8594 deprecation (gap G16).** A legacy type declares
 *    `deprecation: true` + a `sunset` HTTP-date + a `sunsetLink`. The listener emits
 *    `Deprecation`, `Sunset` and a companion `Link: …; rel="sunset"` on **every**
 *    response for the type — a read *and* a write — because a deprecated endpoint is
 *    deprecated regardless of method.
 *
 * An undeclared type (`artists`) carries none of these headers, proving the layer is
 * opt-in per type.
 */
#[Group('spec:fetching')]
final class ResponseHeadersTest extends MusicCatalogKernelTestCase
{
    /** The companion sunset migration-notes URL the `devices` type declares. */
    private const string DEVICES_SUNSET = 'Sat, 31 Dec 2050 23:59:59 GMT';

    // --- G7: declarative HTTP cache headers ----------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function theGenresCollectionCarriesTheOperationOverrideCacheHeaders(): void
    {
        // The collection read resolves the `operations.collection` override
        // (max-age=300) layered over the resource-level directives (s-maxage=86400,
        // public). Vary: Accept rides along so a cache keys per negotiated media type.
        $this->browser()
            ->get('/genres')
            ->assertStatus(Response::HTTP_OK)
            ->assertHeader('Cache-Control', 'max-age=300, public, s-maxage=86400')
            ->assertHeader('Vary', 'Accept');
    }

    #[Test]
    public function aSingleGenreCarriesTheResourceLevelCacheHeaders(): void
    {
        // Create a genre, then read it: the `read` shape has no per-operation
        // override, so it keeps the resource-level max-age=3600.
        $this->browser()->post('/genres', [
            'data' => ['type' => 'genres', 'id' => 'trip-hop', 'attributes' => ['name' => 'Trip-Hop']],
        ])->assertCreated();

        $this->browser()
            ->get('/genres/trip-hop')
            ->assertStatus(Response::HTTP_OK)
            ->assertHeader('Cache-Control', 'max-age=3600, public, s-maxage=86400')
            ->assertHeader('Vary', 'Accept');
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function aGenreWriteNeverCarriesCacheHeaders(): void
    {
        // Caching is applied to safe GET reads only — a successful create (201) is a
        // write, so it gets no Cache-Control (caching a write is wrong).
        $response = $this->browser()->post('/genres', [
            'data' => ['type' => 'genres', 'id' => 'ambient', 'attributes' => ['name' => 'Ambient']],
        ])->assertCreated()->getResponse();
        \assert($response instanceof Response);

        self::assertFalse(
            $this->hasExplicitCacheControl($response),
            'a write must not carry an explicit Cache-Control',
        );
        self::assertFalse($response->headers->has('Vary'));
    }

    #[Test]
    public function anUndeclaredTypeCarriesNoCacheHeaders(): void
    {
        // `artists` declares no cacheHeaders (and the example sets no global default),
        // so a successful read keeps today's no-Cache-Control behaviour.
        $response = $this->browser()->get('/artists/1')->assertStatus(Response::HTTP_OK)->getResponse();
        \assert($response instanceof Response);

        self::assertFalse(
            $this->hasExplicitCacheControl($response),
            'an undeclared type must not carry an explicit Cache-Control',
        );
    }

    // --- G16: RFC 8594 deprecation + sunset ----------------------------------

    #[Test]
    public function aDeprecatedTypeReadCarriesTheDeprecationAndSunsetHeaders(): void
    {
        // Create a device, then read it: the deprecation signal rides the GET.
        $id = $this->createDevice('Living Room Speaker');

        $this->browser()
            ->get('/devices/' . $id)
            ->assertStatus(Response::HTTP_OK)
            ->assertHeader('Deprecation', 'true')
            ->assertHeader('Sunset', self::DEVICES_SUNSET)
            ->assertHeader('Link', '<https://music.example/deprecations/devices>; rel="sunset"');
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function aDeprecatedTypeWriteAlsoCarriesTheDeprecationHeaders(): void
    {
        // Deprecation rides EVERY method — the create (201) carries the same signal as
        // the read, because a deprecated endpoint is deprecated regardless of verb.
        $response = $this->browser()->post('/devices', [
            'data' => ['type' => 'devices', 'attributes' => ['label' => 'Patio Speaker']],
        ])->assertCreated()
            ->assertHeader('Deprecation', 'true')
            ->assertHeader('Sunset', self::DEVICES_SUNSET)
            ->getResponse();
        \assert($response instanceof Response);

        // ...but the write still gets no Cache-Control (deprecation != cacheable).
        self::assertFalse(
            $this->hasExplicitCacheControl($response),
            'a deprecated write must still not carry a Cache-Control',
        );
    }

    #[Test]
    public function anUndeprecatedTypeCarriesNoDeprecationHeaders(): void
    {
        // `artists` is not deprecated, so it carries no Deprecation/Sunset/Link.
        $response = $this->browser()->get('/artists/1')->assertStatus(Response::HTTP_OK)->getResponse();
        \assert($response instanceof Response);

        self::assertFalse($response->headers->has('Deprecation'));
        self::assertFalse($response->headers->has('Sunset'));
        self::assertFalse($response->headers->has('Link'));
    }

    // --- helpers -------------------------------------------------------------

    private function createDevice(string $label): string
    {
        $response = $this->browser()->post('/devices', [
            'data' => ['type' => 'devices', 'attributes' => ['label' => $label]],
        ])->assertCreated()->getResponse();
        \assert($response instanceof Response);

        $decoded = \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));
        $data = $decoded['data'] ?? null;
        \assert(\is_array($data));
        $id = $data['id'] ?? null;
        \assert(\is_string($id));

        return $id;
    }

    /**
     * Whether the response carries a real `Cache-Control` directive beyond the
     * conservative `no-cache, private` default a bare HttpFoundation {@see Response}
     * computes — mirroring the listener's own "explicit caching" detection.
     */
    private function hasExplicitCacheControl(Response $response): bool
    {
        $value = $response->headers->get('Cache-Control', '');

        return $value !== '' && $value !== 'no-cache, private';
    }
}
