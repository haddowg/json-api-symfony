<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Album;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Artist;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Track;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Query\QueryCountingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The include-batch-preloading acceptance suite (backs ADR 0035 + the `doctrine.md`
 * eager-loading section): the reference Doctrine provider batch-loads a read's
 * effective `?include` tree (explicit includes, or a resource's
 * {@see \haddowg\JsonApi\Resource\AbstractResource::getDefaultIncludedRelationships()}
 * fallback) one query per level, so includes do not N+1.
 *
 * It seeds extra artists/albums/tracks beyond the base {@see DataFixtures\Seed} so a
 * bounded query count (≈ one per include level) is clearly distinct from N+1 across
 * the population, then counts the SQL the DBAL logging middleware feeds to the
 * {@see QueryCountingLogger} and asserts the bound — decisively, by toggling the
 * preloader off on the same request and watching the lazy count grow with the album
 * population. The compound documents are asserted correct and proven byte-identical
 * with the preloader disabled — the preload is a pure optimization.
 *
 * Probes ride the (non-cyclic) include trees rooted at `albums` (which the bundled
 * Doctrine provider serves directly, and whose to-many relations are all load-aware
 * so they add no linkage noise): a collection `albums?include=tracks` (one batched
 * tracks load), the nested `albums?include=tracks.album` (a batch per level), and
 * `AlbumResource`'s existing default include `artist` (rendered + preloaded when no
 * `?include` is sent, suppressed by an explicit empty include). `artists` is
 * deliberately avoided: it is served by a custom provider that does not implement the
 * preload capability, so it renders lazily (a witness that the capability is opt-in).
 */
#[Group('spec:fetching-includes')]
final class IncludePreloadTest extends MusicCatalogKernelTestCase
{
    /**
     * Extra artists added on top of the base seed (artists "1"/"2"), each with two
     * albums of two tracks — so an `albums?include=tracks` over the whole population
     * spans many parent rows, making N+1 unmistakable.
     */
    private const int EXTRA_ARTISTS = 6;

    /**
     * The pivot-backed `tracks.playlists` relation is not load-aware in the example,
     * so an included track lazily loads its playlists regardless of `?include` — a
     * fixed side-effect unrelated to the include batching under test. The include-load
     * count excludes any query touching the playlist tables to isolate the batching.
     */
    private const array PLAYLIST_NOISE = ['playlist'];

    private QueryCountingLogger $queries;

    protected function setUp(): void
    {
        // The preloader is suggest-gated: without shipmonk/doctrine-entity-preloader
        // installed the Doctrine provider renders includes lazily, so the batching
        // assertions do not apply — skip the whole suite (the rest of the bundle still
        // works, proven by every other example suite passing). parent::setUp() runs
        // first so the base case's error-handler snapshot/restore stays balanced.
        parent::setUp();

        if (!\class_exists(\ShipMonk\DoctrineEntityPreloader\EntityPreloader::class)) {
            self::markTestSkipped('shipmonk/doctrine-entity-preloader is not installed; includes render lazily.');
        }
    }

    protected function afterBoot(): void
    {
        parent::afterBoot();

        $this->seedExtraGraph();

        // Reset AFTER schema + seed so only the request's queries are counted.
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        \assert($logger instanceof QueryCountingLogger);
        $this->queries = $logger;
        $this->queries->reset();
    }

    // --- requested includes ---------------------------------------------------

    #[Test]
    public function aSingleLevelIncludeOverACollectionIsBatchedNotNPlusOne(): void
    {
        // GET /albums?include=tracks over N albums (one page, all of them): one query
        // for the albums, one batched query for every album's tracks — a bound
        // independent of N, NOT the 1 + N an N+1 fetch would issue.
        $document = $this->fetchDocument('/albums?include=tracks&page[size]=100');

        $albumCount = \count($this->dataOf($document));
        self::assertGreaterThanOrEqual(self::EXTRA_ARTISTS * 2, $albumCount, 'the whole album population is on one page');

        $included = $this->includedIndex($document);
        self::assertArrayHasKey('tracks:1', $included, 'Airbag is included');

        // albums + paginator COUNT + one batched tracks load = ~3 — the include-load
        // count (excluding the unrelated playlist linkage) is a small constant, not
        // proportional to the album count.
        $this->assertIncludeLoadIsBounded(3, $albumCount);
    }

    #[Test]
    public function aNestedIncludeIsBatchedPerLevelNotNTimesM(): void
    {
        // GET /albums?include=tracks.album: one query for albums, one batched for all
        // their tracks (level 1), then the preloader recurses into those tracks'
        // albums (level 2) — which are the primary albums, already in the identity
        // map, so the deep traversal adds NO further round-trips. Either way the count
        // is a small bound per level, never N albums x M tracks of lazy loads.
        $document = $this->fetchDocument('/albums?include=tracks.album&page[size]=100');

        $included = $this->includedIndex($document);
        self::assertArrayHasKey('tracks:1', $included, 'a track is included one level deep');
        self::assertSame('Airbag', $this->attribute($included, 'tracks:1', 'title'));

        // The deep relationship resolves: Airbag's `album` linkage points at album 1.
        $album = $this->relationshipLinkage($included['tracks:1'], 'album');
        self::assertSame(['type' => 'albums', 'id' => '1'], $album);

        $albumCount = \count($this->dataOf($document));
        // albums + COUNT + batched tracks (+ a batched second level when not cached) —
        // a small bound, decisively below the 1-per-row an N+1 would issue.
        $this->assertIncludeLoadIsBounded(4, $albumCount);
    }

    #[Test]
    public function disablingThePreloaderRevealsTheNPlusOneItPrevents(): void
    {
        // The decisive batching proof: the SAME request, preloaded vs lazy. Preloaded,
        // the tracks load is one batched query (a small bound). Lazy (preloader off),
        // each album's tracks load on demand — a count that scales with the album
        // population (the N+1 the preloader exists to prevent).
        $this->fetchDocument('/albums?include=tracks&page[size]=100');
        $batched = $this->queries->countExcluding(self::PLAYLIST_NOISE);

        $albumCount = $this->disablePreloaderAndCountAlbums();

        $this->queries->reset();
        $this->fetchDocument('/albums?include=tracks&page[size]=100');
        $lazy = $this->queries->countExcluding(self::PLAYLIST_NOISE);

        self::assertGreaterThan(
            $batched + 2,
            $lazy,
            \sprintf('lazy issues materially more queries than the batched %d (the N+1)', $batched),
        );
        // Lazy scales with the population; batched does not.
        self::assertGreaterThanOrEqual($albumCount, $lazy, 'the lazy path issues roughly one tracks query per album');
        self::assertLessThan($albumCount, $batched, 'the batched path is bounded below the album population');
    }

    // --- default includes -----------------------------------------------------

    #[Test]
    public function aDefaultIncludeWithNoIncludeParamIsPreloadedAndRendered(): void
    {
        // AlbumResource defaults to include `artist`; with no ?include the default
        // tree is both rendered AND batch-preloaded (one query for every album's
        // artist, not one per album).
        $document = $this->fetchDocument('/albums?page[size]=100');

        $included = $this->includedIndex($document);
        self::assertArrayHasKey('artists:1', $included, 'the default-included artist renders');

        $albumCount = \count($this->dataOf($document));
        // albums + COUNT + one batched artist load = ~3.
        $this->assertIncludeLoadIsBounded(3, $albumCount);
    }

    #[Test]
    public function anExplicitEmptyIncludeOverridesDefaultsAndPreloadsNothing(): void
    {
        // ?include= (empty) overrides the resource default to NOTHING — no included
        // member, and no batched include query.
        $document = $this->fetchDocument('/albums?include=&page[size]=100');

        self::assertArrayNotHasKey('included', $document, 'an explicit empty include suppresses defaults');

        // Only the primary fetch (+ paginator COUNT) runs — no include batch.
        self::assertLessThanOrEqual(2, $this->queries->countExcluding(self::PLAYLIST_NOISE), $this->diagnose());
    }

    // --- correctness: identical with and without the preloader ----------------

    #[Test]
    public function theCompoundDocumentIsIdenticalWithAndWithoutThePreloader(): void
    {
        $withPreloader = $this->fetchDocument('/albums?include=tracks.album&page[size]=100');

        // Disable the preloader on the resolved Doctrine provider (and clear the
        // identity map so it is a genuine cold, lazy read) and re-issue the same
        // request: the rendered document must be byte-identical — preloading is a pure
        // optimization, never a content change.
        $this->disablePreloader();
        $withoutPreloader = $this->fetchDocument('/albums?include=tracks.album&page[size]=100');

        self::assertSame(
            \json_encode($withPreloader),
            \json_encode($withoutPreloader),
            'the compound document is identical with and without the preloader',
        );
    }

    // --- helpers --------------------------------------------------------------

    /**
     * Asserts the include-load query count (excluding the unrelated playlist linkage
     * noise) is a small BOUND — and, decisively, below the 1-per-row an N+1 fetch
     * would issue across the population.
     */
    private function assertIncludeLoadIsBounded(int $max, int $population): void
    {
        $count = $this->queries->countExcluding(self::PLAYLIST_NOISE);
        self::assertLessThanOrEqual($max, $count, $this->diagnose());
        self::assertLessThan($population, $count, 'a bounded include-load count is below the 1-per-row an N+1 fetch would issue: ' . $this->diagnose());
    }

    private function diagnose(): string
    {
        return \sprintf(
            "expected a bounded include-load query count; ran %d total queries (%d excluding playlist noise):\n%s",
            $this->queries->count(),
            $this->queries->countExcluding(self::PLAYLIST_NOISE),
            \implode("\n", $this->queries->queries()),
        );
    }

    /**
     * Seeds {@see EXTRA_ARTISTS} more artists, each with two albums of two tracks, so
     * the include population is large enough that N+1 is unmistakable against the
     * bounded batch.
     */
    private function seedExtraGraph(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        for ($a = 0; $a < self::EXTRA_ARTISTS; ++$a) {
            $artistId = \sprintf('extra-artist-%d', $a);
            $artist = new Artist(
                id: $artistId,
                name: \sprintf('Extra Artist %d', $a),
                slug: \sprintf('extra-artist-%d', $a),
                trackCount: 4,
                createdAt: new \DateTimeImmutable('2020-01-01T00:00:00+00:00'),
            );
            $entityManager->persist($artist);

            for ($b = 0; $b < 2; ++$b) {
                $album = new Album(
                    id: \sprintf('extra-album-%d-%d', $a, $b),
                    title: \sprintf('Extra Album %d-%d', $a, $b),
                    published: true,
                    artist: $artist,
                );
                $entityManager->persist($album);

                for ($t = 0; $t < 2; ++$t) {
                    $track = new Track(
                        id: \sprintf('extra-track-%d-%d-%d', $a, $b, $t),
                        title: \sprintf('Extra Track %d-%d-%d', $a, $b, $t),
                        trackNumber: $t + 1,
                        length_seconds: 200,
                        genres: ['rock'],
                        album: $album,
                    );
                    $entityManager->persist($track);
                }
            }
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * Turns off include preloading on the bundled Doctrine `albums` provider, so the
     * next request renders the includes lazily — the "without preloader" arm of the
     * identical-output and N+1 proofs. The identity map is cleared so the next read is
     * genuinely cold (not served from the preloaded units of work).
     */
    private function disablePreloader(): void
    {
        $registry = static::getContainer()->get(DataProviderRegistry::class);
        \assert($registry instanceof DataProviderRegistry);

        // `albums` is served by the bundled Doctrine provider (no custom override),
        // so the include preloader lives on it directly.
        $provider = $registry->forType('albums');
        self::assertInstanceOf(DoctrineDataProvider::class, $provider);

        $provider->disableIncludePreloading();

        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->clear();
    }

    /**
     * Disables the preloader and returns the album population (so the N+1 proof can
     * assert the lazy count scales with it).
     */
    private function disablePreloaderAndCountAlbums(): int
    {
        $this->disablePreloader();

        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return (int) $entityManager->createQuery('SELECT COUNT(a.id) FROM ' . Album::class . ' a')->getSingleScalarResult();
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDocument(string $path): array
    {
        $response = $this->handle($path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<mixed>
     */
    private function dataOf(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        return \array_values($data);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, array<string, mixed>>
     */
    private function includedIndex(array $document): array
    {
        $included = $document['included'] ?? null;
        self::assertIsArray($included);

        $index = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            $type = $resource['type'] ?? null;
            $id = $resource['id'] ?? null;
            self::assertIsString($type);
            self::assertIsString($id);
            /** @var array<string, mixed> $resource */
            $index[$type . ':' . $id] = $resource;
        }

        return $index;
    }

    /**
     * @param array<string, array<string, mixed>> $index
     */
    private function attribute(array $index, string $key, string $name): mixed
    {
        $attributes = $index[$key]['attributes'] ?? null;
        self::assertIsArray($attributes);

        return $attributes[$name] ?? null;
    }

    /**
     * The to-one linkage identifier (`{type, id}`) of `$relationshipName` on an
     * included resource.
     *
     * @param array<string, mixed> $resource
     *
     * @return array{type: mixed, id: mixed}
     */
    private function relationshipLinkage(array $resource, string $relationshipName): array
    {
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);
        $relationship = $relationships[$relationshipName] ?? null;
        self::assertIsArray($relationship);
        $data = $relationship['data'] ?? null;
        self::assertIsArray($data);

        return ['type' => $data['type'] ?? null, 'id' => $data['id'] ?? null];
    }
}
