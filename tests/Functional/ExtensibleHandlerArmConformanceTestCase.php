<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The extensible-handler-seam acceptance suite (core ADR 0078, bundle ADR 0083): a
 * **custom filter** ({@see \haddowg\JsonApiBundle\Tests\Functional\App\Query\RelationCountAtLeast})
 * and a **custom sort** ({@see \haddowg\JsonApiBundle\Tests\Functional\App\Query\OrderByRelationCount})
 * that neither built-in handler recognises, executed end-to-end over HTTP only
 * because a registered arm teaches each provider to run them.
 *
 * Abstract over the kernel so the **same assertions** run against the in-memory
 * provider ({@see InMemoryHandlerArmTest}, where the arm is the conformance witness)
 * and the Doctrine provider ({@see DoctrineHandlerArmTest}, where the arm pushes down
 * to `SIZE(...)` DQL). The canonical fixtures seed `articles` 1-5 with comment counts
 * 2, 1, 2, 0, 0 (ids 1, 2, 3, 4, 5).
 */
abstract class ExtensibleHandlerArmConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aCustomFilterArmKeepsRowsByRelationCount(): void
    {
        // SIZE(comments) >= N, pushed down on Doctrine / counted in memory — identical.
        self::assertSame(['1', '2', '3'], $this->sortedIds('/articles?filter[minComments]=1'));
        self::assertSame(['1', '3'], $this->sortedIds('/articles?filter[minComments]=2'));
        self::assertSame([], $this->sortedIds('/articles?filter[minComments]=3'));
        // A zero bound keeps the comment-less articles 4 and 5 too.
        self::assertSame(['1', '2', '3', '4', '5'], $this->sortedIds('/articles?filter[minComments]=0'));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aCustomSortArmOrdersByRelationCount(): void
    {
        // commentCount asc, id asc tie-breaker: counts 0,0,1,2,2 → 4,5,2,1,3. The
        // arm key weaves into the cascade ahead of the `id` field sort on both
        // providers (in-memory lexicographic usort / Doctrine ORDER BY SIZE(), id).
        self::assertSame(['4', '5', '2', '1', '3'], $this->orderedIds('/articles?sort=commentCount,id'));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aCustomSortArmHonoursDescendingDirection(): void
    {
        // -commentCount, id: counts 2,2,1,0,0 with id asc within a tie → 1,3,2,4,5.
        self::assertSame(['1', '3', '2', '4', '5'], $this->orderedIds('/articles?sort=-commentCount,id'));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function twoCustomSortsOfTheSameArmCoexistInOneRequest(): void
    {
        // commentCount asc, then editorCount asc, then id: two applications of the one
        // count arm in a single request — each must emit a DISTINCT push-down alias or
        // the query fails. Comment counts 2,1,2,0,0; editor counts 2,1,1,0,0 (ids 1-5).
        // Groups by commentCount: {4,5}(0), {2}(1), {1,3}(2); within {1,3} editorCount
        // breaks the tie 3(1) before 1(2) → 4,5,2,3,1.
        self::assertSame(['4', '5', '2', '3', '1'], $this->orderedIds('/articles?sort=commentCount,editorCount,id'));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aCustomSortComposesWithPagination(): void
    {
        // commentCount asc, id → 4,5,2,1,3; the first page of size 2 is 4,5 — proving
        // the push-down sort survives the LIMIT/OFFSET window (the Doctrine HIDDEN
        // ORDER BY select is carried into the paged query).
        self::assertSame(['4', '5'], $this->orderedIds('/articles?sort=commentCount,id&page[size]=2'));
    }

    /**
     * The numerically-sorted ids of `$path`'s primary `articles` data — a stable
     * order for set-membership (filter) assertions independent of default ordering.
     *
     * @return list<string>
     */
    private function sortedIds(string $path): array
    {
        $ids = $this->ids($this->fetchDocument($path));
        \sort($ids, \SORT_NUMERIC);

        return $ids;
    }

    /**
     * The ids of `$path`'s primary `articles` data **in document order** — for
     * asserting the order a sort produced.
     *
     * @return list<string>
     */
    private function orderedIds(string $path): array
    {
        return $this->ids($this->fetchDocument($path));
    }

    /**
     * Fetches `$path` and returns the decoded document, asserting a 200 JSON:API
     * response.
     *
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
     * The ids of the document's primary `articles` data, in document order.
     *
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
            self::assertSame('articles', $resource['type'] ?? null);

            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }
}
