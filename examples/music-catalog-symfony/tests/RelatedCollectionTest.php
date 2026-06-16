<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The related-collection acceptance suite (backs `relationships.md` / `doctrine.md`):
 * the related to-many endpoint `GET /{type}/{id}/{rel}` as a real queryable,
 * paginated collection, exercising **both** Doctrine push-down branches (ADR 0031)
 * and the unpaginated baseline.
 *
 *  - FK fast-path — `albums.tracks` (the related `Track` carries the owning album
 *    FK): scoped by that FK, with a per-relation `perPage=2` paginator.
 *  - `IN`-subquery — `playlists.tracks` (a many-to-many): scoped by an `IN`
 *    subquery rooted on the related entity, with its own `perPage=2` paginator.
 *  - `?filter`/`?sort` scope against the **related** (`tracks`) vocabulary — the
 *    `tracks` resource's `explicit` default filter even hides the explicit member
 *    of a related collection.
 *  - a relation without its own paginator (`tracks.playlists`) falls back to the
 *    server's default paginator (capped at `json_api.pagination.max_per_page`),
 *    per the documented relation → related resource → server-default chain.
 */
#[Group('spec:fetching-relationships')]
final class RelatedCollectionTest extends MusicCatalogKernelTestCase
{
    private const string PLAYLIST_ID = '00000000-0000-4000-8000-000000000001';

    // --- FK fast-path (albums.tracks) ----------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aFkScopedRelatedCollectionWindowsAndCarriesPageMeta(): void
    {
        // Album 1's tracks are the non-explicit 1 and 3 (track 2 is explicit and
        // hidden by the related type's default filter). perPage is 2, so page 1 is
        // the full window with page meta and navigation links.
        $document = $this->fetch('/albums/1/tracks');

        self::assertSame(['1', '3'], $this->ids($document));

        $page = $this->pageMeta($document);
        self::assertSame(1, $page['currentPage'] ?? null);
        self::assertSame(2, $page['perPage'] ?? null);
        self::assertSame(2, $page['total'] ?? null);

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertArrayHasKey('self', $links);
        self::assertArrayHasKey('first', $links);
        self::assertArrayHasKey('last', $links);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aFkScopedRelatedCollectionSecondPageIsEmptyWithAPrevLink(): void
    {
        $document = $this->fetch('/albums/1/tracks?page[size]=2&page[number]=2');

        self::assertSame([], $this->ids($document));

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertArrayHasKey('prev', $links);
        self::assertArrayNotHasKey('next', $links);

        // Page links are scoped to the related-collection URL the client hit.
        self::assertStringContainsString('/albums/1/tracks', $this->href($links['prev']));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aFkScopedRelatedCollectionSortsByTheRelatedVocabulary(): void
    {
        // sort=-title against the tracks vocabulary: "Exit Music…" > "Airbag", so
        // track 3 precedes track 1.
        $document = $this->fetch('/albums/1/tracks?sort=-title');

        self::assertSame(['3', '1'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aFkScopedRelatedCollectionFiltersByTheRelatedVocabulary(): void
    {
        // The explicit default filter hides the explicit track from the related
        // collection, but explicit=true surfaces it — proving the filter scopes
        // against the related (tracks) vocabulary, not the parent album's.
        $document = $this->fetch('/albums/1/tracks?filter[explicit]=true');

        self::assertSame(['2'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aFkScopedRelatedCollectionAppliesALikeFilter(): void
    {
        $document = $this->fetch('/albums/1/tracks?filter[title]=air');

        self::assertSame(['1'], $this->ids($document));
    }

    // --- IN-subquery (playlists.tracks, many-to-many) ------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aManyToManyRelatedCollectionScopesViaAnInSubqueryAndPaginates(): void
    {
        // The Morning Mix holds tracks 1 and 2, but track 2 is explicit (hidden by
        // the default filter), so the related collection is just track 1. The
        // many-to-many is scoped by an IN subquery rooted on the related entity.
        $document = $this->fetch('/playlists/' . self::PLAYLIST_ID . '/tracks');

        self::assertSame(['1'], $this->ids($document));

        $page = $this->pageMeta($document);
        self::assertSame(2, $page['perPage'] ?? null);
        self::assertSame(1, $page['total'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aManyToManyRelatedCollectionWindowsByExplicitPageParams(): void
    {
        // page[size]=1 with a second page that is empty (one member): the window
        // params override the per-relation default and the meta agrees.
        $document = $this->fetch('/playlists/' . self::PLAYLIST_ID . '/tracks?page[size]=1&page[number]=2');

        self::assertSame([], $this->ids($document));

        $page = $this->pageMeta($document);
        self::assertSame(2, $page['currentPage'] ?? null);
        self::assertSame(1, $page['perPage'] ?? null);
        self::assertSame(1, $page['total'] ?? null);
    }

    // --- server-default fallback ---------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aRelationWithoutItsOwnPaginatorFallsBackToTheServerDefault(): void
    {
        // `tracks.playlists` declares no per-relation paginator, so its related
        // collection falls back to the server's default paginator (relation →
        // related resource → server default). The default carries the page-size cap
        // (json_api.pagination.max_per_page), so the collection is paginated.
        $document = $this->fetch('/tracks/1/playlists');

        self::assertSame([self::PLAYLIST_ID], $this->ids($document));

        $page = $this->pageMeta($document);
        self::assertSame(1, $page['total'] ?? null);
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $path): array
    {
        $response = $this->handle($path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function ids(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function pageMeta(array $document): array
    {
        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);

        $page = $meta['page'] ?? null;
        self::assertIsArray($page);

        /** @var array<string, mixed> $page */
        return $page;
    }

    private function href(mixed $link): string
    {
        if (\is_array($link) && isset($link['href']) && \is_string($link['href'])) {
            return $link['href'];
        }

        self::assertIsString($link);

        return $link;
    }
}
