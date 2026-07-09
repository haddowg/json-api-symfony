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
