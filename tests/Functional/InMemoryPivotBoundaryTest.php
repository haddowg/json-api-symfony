<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\PivotBoundaryTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The in-memory pivot boundary witness: pivot data is Doctrine-only (it requires an
 * association entity the in-memory provider cannot model). So on the in-memory
 * `playlists.tracks` related endpoint a pivot `?filter`/`?sort` key is unrecognised
 * (400) and no pivot meta renders — while the track's OWN `?filter[title]` works,
 * proving the related collection itself functions and only the PIVOT keys are
 * absent.
 *
 * The boundary extends to a PRIMARY document (bundle ADR 0102): a `withData()` pivot
 * relation's default-rendered linkage on `GET /playlists/1` carries NO `meta.pivot`,
 * because the in-memory provider is not pivot-aware — the wrap is a clean no-op.
 */
final class InMemoryPivotBoundaryTest extends JsonApiFunctionalTestCase
{
    private const string BASE_URI = 'https://example.test';

    protected static function getKernelClass(): string
    {
        return PivotBoundaryTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aPivotFilterIsUnrecognisedOnTheInMemoryRelatedEndpoint(): void
    {
        $response = $this->handle(self::BASE_URI . '/playlists/1/tracks?filter[position]=1');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aPivotSortIsUnrecognisedOnTheInMemoryRelatedEndpoint(): void
    {
        $response = $this->handle(self::BASE_URI . '/playlists/1/tracks?sort=position');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theInMemoryRelatedEndpointStillWorksWithoutPivot(): void
    {
        // The related collection itself works in-memory (only pivot is absent): a
        // plain fetch returns the tracks, and the track's own filter narrows them —
        // with no pivot meta on any member.
        $response = $this->handle(self::BASE_URI . '/playlists/1/tracks?filter[title]=o');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->decode($response);
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertNotSame([], $data);

        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $meta = $resource['meta'] ?? [];
            self::assertIsArray($meta);
            self::assertArrayNotHasKey('pivot', $meta);
        }
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aPrimaryDocumentDefaultLinkageCarriesNoPivotInMemory(): void
    {
        // `dataTracks` renders its linkage data by default (withData()). On a primary
        // GET /playlists/1 over the in-memory provider the linkage identifiers carry NO
        // meta.pivot — the documented boundary holds on a primary document too (bundle
        // ADR 0102): the in-memory provider is not pivot-aware, so the wrap is a no-op.
        $response = $this->handle(self::BASE_URI . '/playlists/1');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->decode($response);
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        $dataTracks = $relationships['dataTracks'] ?? null;
        self::assertIsArray($dataTracks);

        $linkage = $dataTracks['data'] ?? null;
        self::assertIsArray($linkage);
        self::assertNotSame([], $linkage, 'the default-rendered linkage carries members');

        foreach ($linkage as $identifier) {
            self::assertIsArray($identifier);
            $meta = $identifier['meta'] ?? [];
            self::assertIsArray($meta);
            self::assertArrayNotHasKey('pivot', $meta, 'no pivot on the in-memory primary linkage');
        }
    }
}
