<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Provider\OverridingArtistProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The provider-precedence contract (seam 2, priority-shadow half; backs
 * `custom-data-providers.md` / `data-layer.md`): an application provider registered
 * by plain autoconfiguration (default tag priority `0`) shadows the bundled
 * Doctrine fallback (`-128`) for the type it supports — no priority configuration.
 *
 * The {@see OverridingArtistProvider} takes over `artists`: it answers a sentinel
 * id (`override`) the database never contains — so a read of it is attributable to
 * the override alone — and delegates every other `artists` read to the injected
 * Doctrine provider, so the real endpoint stays intact. The Doctrine provider is
 * still wired (the resource maps an entity); the override wins by priority, not by
 * the fallback's absence.
 */
#[Group('spec:fetching')]
final class CustomProviderTest extends MusicCatalogKernelTestCase
{
    #[Test]
    public function theApplicationProviderShadowsTheDoctrineFallbackForArtists(): void
    {
        $registry = static::getContainer()->get(DataProviderRegistry::class);
        \assert($registry instanceof DataProviderRegistry);

        // The registry returns the first provider whose supports() is true, in
        // descending tag priority — the default-priority override outranks the -128
        // Doctrine fallback for `artists`.
        self::assertInstanceOf(OverridingArtistProvider::class, $registry->forType('artists'));

        // The Doctrine provider is still registered (it serves every other entity
        // type) — the override wins by priority, not by the fallback being absent.
        self::assertInstanceOf(DoctrineDataProvider::class, static::getContainer()->get(DoctrineDataProvider::class));
    }

    #[Test]
    public function aReadOfTheSentinelIdIsServedByTheApplicationProvider(): void
    {
        // The database holds no artist with this id, so a 200 here can only come from
        // the override provider — proof the override is consulted ahead of Doctrine.
        $response = $this->handle('/artists/' . OverridingArtistProvider::SENTINEL_ID);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('artists', $data['type'] ?? null);
        self::assertSame(OverridingArtistProvider::SENTINEL_ID, $data['id'] ?? null);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame(OverridingArtistProvider::NAME, $attributes['name'] ?? null);
    }

    #[Test]
    public function aRealArtistReadDelegatesThroughTheOverrideToDoctrine(): void
    {
        // A seeded id the override does not special-case is delegated to the Doctrine
        // provider, so the real `/artists` endpoint is unchanged by the shadow.
        $data = $this->decode($this->handle('/artists/1'))['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('artists', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('Radiohead', $attributes['name'] ?? null);
    }
}
