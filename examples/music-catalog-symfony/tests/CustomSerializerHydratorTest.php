<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The per-resource serializer/hydrator override witnesses (ADR 0023; backs
 * `custom-serializers-hydrators.md`): a resource keeps its type/route/registration
 * role but delegates the wire shape to a hand-written serializer/hydrator, each
 * resolved through the container **with a bound constructor argument** — so a
 * successful read/write proves DI resolution (a plain `new`, core's registration
 * model, could not supply the argument).
 *
 *  - {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Serializer\TrackSerializer}
 *    (`#[AsJsonApiResource(serializer: …)]`) wins for `tracks` reads: it surfaces
 *    its bound `$catalogTag` in `meta.served_by` and derives `displayTitle` across
 *    two columns, while the resource still hydrates writes.
 *  - {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Hydrator\PlaylistHydrator}
 *    (`#[AsJsonApiResource(hydrator: …)]`) wins for `playlists` writes: it fans one
 *    `title` member out to the title + a derived read-only `slug` using its bound
 *    `$slugSeparator`, while the resource still serializes reads.
 *
 * The example app overrides no `uriType` (core's domain does not), so that segment
 * divergence is documented in prose only — never witnessed here.
 */
#[Group('spec:fetching')]
final class CustomSerializerHydratorTest extends MusicCatalogKernelTestCase
{
    #[Test]
    public function theOverrideSerializerRendersTracksWithItsBoundDependencyAndDerivedAttribute(): void
    {
        $data = $this->decode($this->handle('/tracks/1'))['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('tracks', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);

        // The bound constructor argument surfaced on the wire — proof the bundle
        // resolved the override through the container with its dependency.
        $meta = $data['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertSame('music-catalog', $meta['served_by'] ?? null);

        // Computed by the serializer across trackNumber + title on read.
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('1. Airbag', $attributes['displayTitle'] ?? null);
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function theOverrideHydratorDerivesTheSlugFromTitleUsingItsBoundSeparator(): void
    {
        $response = $this->handle('/playlists', 'POST', [
            'data' => [
                'type' => 'playlists',
                'id' => '00000000-0000-4000-8000-0000000000ab',
                'attributes' => [
                    'title' => 'Road Trip Hits',
                    'public' => true,
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('playlists', $data['type'] ?? null);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('Road Trip Hits', $attributes['title'] ?? null);
        // The fan-out: one `title` member derived the read-only slug, joined by the
        // bound `$slugSeparator` ('-') — proof the override hydrator was DI-resolved.
        self::assertSame('road-trip-hits', $attributes['slug'] ?? null);
    }

    #[Test]
    public function theOverrideSerializerAndHydratorCoexistOnTheirOwnResources(): void
    {
        // tracks reads through the override serializer (meta marker present)...
        $track = $this->decode($this->handle('/tracks/1'))['data'] ?? null;
        self::assertIsArray($track);
        $trackMeta = $track['meta'] ?? null;
        self::assertIsArray($trackMeta);
        self::assertArrayHasKey('served_by', $trackMeta);

        // ...while playlists serialize through the resource's own (default) serializer,
        // which carries no such meta marker — the override is per-type, not global.
        $playlist = $this->decode($this->handle('/playlists/00000000-0000-4000-8000-000000000001'))['data'] ?? null;
        self::assertIsArray($playlist);
        self::assertSame('playlists', $playlist['type'] ?? null);
        self::assertArrayNotHasKey('served_by', (array) ($playlist['meta'] ?? []));
    }
}
