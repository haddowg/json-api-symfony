<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The batched-include CURSOR (keyset) pagination acceptance suite, asserted
 * byte-identical on the in-memory ({@see InMemoryCursorIncludeBatchTest}) and
 * Doctrine-sqlite ({@see DoctrineCursorIncludeBatchTest}) kernels over the shared
 * `cursorShelves` → `widgets` declaration (the relation declares its OWN
 * {@see \haddowg\JsonApi\Pagination\CursorPaginator}, default size 2).
 *
 * An include carries no cursor token (the Relationship Queries profile pins the
 * included page to page 1), so a cursor-resolved include is always a FIRST cursor
 * page per parent: the batcher mints the forward cursor from each parent's boundary
 * row and renders a {@see \haddowg\JsonApi\Pagination\CursorBasedPage} — the
 * relationship object emits `first`/`next` (the minted `page[after]`) and never
 * `prev`/`last`. Shelf 1 holds every widget, so under a PK-only keyset its first
 * page is widgets `1,2` and its `next` cursor continues to `3,4` (core ADR 0063).
 * The Doctrine per-parent keyset push-down must match the in-memory witness.
 */
abstract class CursorIncludeBatchConformanceTestCase extends JsonApiFunctionalTestCase
{
    /** Negotiates the Relationship-Queries (windowing) profile that windows an included to-many to page 1. */
    private const string PROFILE_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-pagination')]
    public function aCursorResolvedIncludeRendersAFirstCursorPagePerParent(): void
    {
        $document = $this->includeDocument('/cursorShelves/1?include=widgets');

        $widgets = $this->relationshipObject($document['data'] ?? null, 'widgets');

        // Page 1 of the cursor-paginated relation: the two lowest-id widgets under the
        // PK-only keyset (size 2), byte-identical on both providers.
        self::assertSame(['1', '2'], $this->linkageIds($widgets));

        // A first cursor page: `first` and a `next` carrying the minted opaque cursor
        // token are emitted; `prev` and `last` are omitted (an include is always page 1,
        // and a cursor page has no total to locate a last page).
        $links = $widgets['links'] ?? [];
        self::assertIsArray($links);
        self::assertArrayHasKey('first', $links);
        self::assertArrayHasKey('next', $links);
        self::assertStringContainsString('page%5Bafter%5D=', $this->href($links['next']));
        self::assertArrayNotHasKey('prev', $links);
        self::assertArrayNotHasKey('last', $links);
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-pagination')]
    public function theMintedIncludeCursorContinuesCorrectlyOnTheRelationshipEndpoint(): void
    {
        // The `next` cursor an INCLUDE mints must be a real keyset boundary: following
        // it on the relationship-linkage endpoint yields the next page (`3,4`), proving
        // the per-parent boundary row was minted under the same keyset the endpoint
        // continues from — byte-identical on both providers.
        $document = $this->includeDocument('/cursorShelves/1?include=widgets');
        $links = $this->relationshipObject($document['data'] ?? null, 'widgets')['links'] ?? null;
        self::assertIsArray($links);
        $next = $this->href($links['next'] ?? null);

        $page2 = $this->decode($this->handle($this->relativePath($next)));
        $data = $page2['data'] ?? null;
        self::assertIsArray($data);
        $ids = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            $ids[] = $identifier['id'] ?? null;
        }

        self::assertSame(['3', '4'], $ids);
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-pagination')]
    public function aCollectionIncludeCursorsOnANullableColumnWithMixedSurplusAcrossParents(): void
    {
        // A COLLECTION include windows the whole page of parents in ONE cursor window on the
        // Doctrine provider (the N→1 collapse) — sorted on the NULLABLE `priority`, so the
        // forced NULL=largest `CASE … IS NULL …` term composes INSIDE `ROW_NUMBER()`. Shelf 1
        // holds every widget (nulls 3, 6) and shelf 3 holds { 8 (priority 20), 6 (null) }, so
        // the null bucket is interleaved across the partitions of the same window and the
        // witness (in-memory) and the push-down (SQL) must render the SAME page for each —
        // refereeing exactly the NULL-inside-window composition.
        //
        // priority asc, id-tiebreak asc: shelf 1 (1..8) orders 2(10),7(10),5(20),8(20),1(30),
        // 4(30),3(null),6(null) → page 1 = [2, 7] with a further page (EIGHT > size 2 → a
        // `next`); shelf 3 (6, 8) orders 8(20),6(null) → page 1 = [8, 6] EXACTLY the page (no
        // surplus → NO `next`). Shelf 3's null member (6) lands ON its page, proving
        // NULL=largest orders the partition, not just excludes it.
        $document = $this->includeDocument('/cursorShelves?include=widgets&relatedQuery[widgets][sort]=priority');

        $shelf1 = $this->relationshipObject($this->resourceWithId($document, '1'), 'widgets');
        self::assertSame(['2', '7'], $this->linkageIds($shelf1));
        $shelf1Links = $shelf1['links'] ?? null;
        self::assertIsArray($shelf1Links);
        self::assertArrayHasKey('next', $shelf1Links, 'the surplus partition renders a next link');
        self::assertStringContainsString('page%5Bafter%5D=', $this->href($shelf1Links['next']));
        self::assertStringContainsString('sort=priority', $this->href($shelf1Links['next']));

        $shelf3 = $this->relationshipObject($this->resourceWithId($document, '3'), 'widgets');
        self::assertSame(['8', '6'], $this->linkageIds($shelf3));
        $shelf3Links = $shelf3['links'] ?? null;
        self::assertIsArray($shelf3Links);
        self::assertArrayNotHasKey('next', $shelf3Links, 'a partition with no surplus renders no next link');
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-pagination')]
    public function anInverseFkCollectionIncludeCursorsOnANullableColumnWithMixedSurplusAcrossParents(): void
    {
        // The INVERSE-FK complement of the ManyToMany case above: `cursorGroups → widgets` is a
        // OneToMany (the related widget carries the owning `group_id` FK), so on the Doctrine
        // provider the whole page of groups windows in ONE inverse-FK query (partition by the
        // owning FK, no join table), refereed against the in-memory witness. Sorted on the
        // NULLABLE `priority`, both partitions carry a null member (group 1 → id 3, group 2 →
        // id 6), so the forced NULL=largest `CASE … IS NULL …` term composes INSIDE
        // `ROW_NUMBER()` across the partitions and both providers must render the SAME page.
        //
        // priority asc, id-tiebreak asc: group 1 (1,2,3,4,5,7) orders 2(10),7(10),5(20),1(30),
        // 4(30),3(null) → page 1 = [2, 7] with a further page (SIX > size 2 → a `next`); group 2
        // (6, 8) orders 8(20),6(null) → page 1 = [8, 6] EXACTLY the page (no surplus → NO
        // `next`). Group 2's null member (6) lands LAST on its page, proving NULL=largest orders
        // the partition rather than just excluding it.
        $document = $this->includeDocument('/cursorGroups?include=widgets&relatedQuery[widgets][sort]=priority');

        $group1 = $this->relationshipObject($this->resourceWithId($document, '1'), 'widgets');
        self::assertSame(['2', '7'], $this->linkageIds($group1));
        $group1Links = $group1['links'] ?? null;
        self::assertIsArray($group1Links);
        self::assertArrayHasKey('next', $group1Links, 'the surplus partition renders a next link');
        self::assertStringContainsString('page%5Bafter%5D=', $this->href($group1Links['next']));
        self::assertStringContainsString('sort=priority', $this->href($group1Links['next']));

        $group2 = $this->relationshipObject($this->resourceWithId($document, '2'), 'widgets');
        self::assertSame(['8', '6'], $this->linkageIds($group2));
        $group2Links = $group2['links'] ?? null;
        self::assertIsArray($group2Links);
        self::assertArrayNotHasKey('next', $group2Links, 'a partition with no surplus renders no next link');
    }

    /**
     * The primary-collection resource carrying `$id`, resolved out of the document's `data`
     * list so a per-parent relationship object can be addressed on a collection include.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function resourceWithId(array $document, string $id): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        foreach ($data as $resource) {
            if (\is_array($resource) && ($resource['id'] ?? null) === $id) {
                /** @var array<string, mixed> $resource */
                return $resource;
            }
        }

        self::fail(\sprintf('No cursorShelves resource with id "%s" in the collection.', $id));
    }

    /**
     * Fetches `$path` under the Relationship-Queries profile (which windows an included
     * to-many to page 1) and returns the decoded document.
     *
     * @return array<string, mixed>
     */
    private function includeDocument(string $path): array
    {
        $response = $this->handle($path, extraServer: ['HTTP_ACCEPT' => self::PROFILE_ACCEPT]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * The named relationship object of a resource.
     *
     * @param mixed $resource
     *
     * @return array<string, mixed>
     */
    private function relationshipObject(mixed $resource, string $relationship): array
    {
        self::assertIsArray($resource);
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);

        $object = $relationships[$relationship] ?? null;
        self::assertIsArray($object, \sprintf('relationship "%s" is present', $relationship));

        /** @var array<string, mixed> $object */
        return $object;
    }

    /**
     * @param array<string, mixed> $relationshipObject
     *
     * @return list<string>
     */
    private function linkageIds(array $relationshipObject): array
    {
        $data = $relationshipObject['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            self::assertSame('cursorWidgets', $identifier['type'] ?? null);
            $id = $identifier['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    private function href(mixed $link): string
    {
        if (\is_array($link) && \is_string($link['href'] ?? null)) {
            return $link['href'];
        }

        self::assertIsString($link);

        return $link;
    }

    /**
     * The path + query of an absolute link, for re-issuing through the test kernel.
     */
    private function relativePath(string $url): string
    {
        $path = (string) \parse_url($url, \PHP_URL_PATH);
        $query = \parse_url($url, \PHP_URL_QUERY);

        return $query !== null && $query !== false && $query !== '' ? $path . '?' . $query : $path;
    }
}
