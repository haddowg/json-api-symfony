<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The dual-provider acceptance suite for the **`WhereThrough` traversal filter**
 * (G8, core ADR 0063): a correlated `EXISTS-ANY` semi-join over a dotted
 * relationship path — `filter[author.name]` keeps an article whose author's name
 * matches; `filter[comments.body]` keeps one with SOME matching comment;
 * `filter[commentArticleTitle]` (`comments.article.title`) chains the hops. The
 * filter is **portable**, so the SAME assertions run against the in-memory provider
 * ({@see InMemoryWhereThroughTest}, core's `ArrayFilterHandler` witness) and the
 * Doctrine provider ({@see DoctrineWhereThroughTest}, a correlated `EXISTS` DQL
 * subquery) for byte-parity — a failure on one but not the other localises to that
 * provider's execution.
 *
 * The traversal never hydrates the relation and never touches the primary `SELECT`
 * (it is a semi-join, not a fetch-join), so it neither duplicates rows nor needs a
 * `DISTINCT`, and a to-one and a to-many hop translate identically.
 *
 * The declarations live on the shared
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Resource\ConstrainedFilterArticleResource}
 * (both kernels serve it). With the canonical {@see \haddowg\JsonApiBundle\Tests\Functional\App\ArticleFixtures}:
 * articles `1`-`5`; author `1` = "Ada Lovelace" (articles 1, 3), author `2` =
 * "Grace Hopper" (articles 2, 4), article 5 authorless; comments wired 1→[1,2],
 * 2→[3], 3→[4,5], 4→[], 5→[]; editors (many-to-many) 1→[1,2], 2→[1], 3→[2].
 */
abstract class WhereThroughConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aSingleHopToOneTraversalMatchesByTheLeafAttribute(): void
    {
        // filter[author.name]=Ada Lovelace — path-as-key, a to-one hop then the leaf
        // attribute. Articles 1 and 3 are authored by Ada.
        self::assertSame(['1', '3'], $this->filteredIds('author.name', 'Ada Lovelace'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aSingleHopToManyTraversalIsExistsAny(): void
    {
        // filter[comments.body]=detail (fluent `like`). Only comment 3 ("Could use
        // more detail.") contains "detail"; it belongs to article 2 — EXISTS-ANY keeps
        // the parent that has SOME matching comment.
        self::assertSame(['2'], $this->filteredIds('comments.body', 'detail'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aMultiHopTraversalChainsTheRelationshipSegments(): void
    {
        // filter[commentArticleTitle]=JSON:API in PHP — a to-many → to-one → attribute
        // chain (comments.article.title). Article 1's comments (1, 2) belong to article
        // 1, whose title is "JSON:API in PHP", so only article 1 matches: the chained
        // joins inside one EXISTS subquery.
        self::assertSame(['1'], $this->filteredIds('commentArticleTitle', 'JSON:API in PHP'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aManyToManyHopTraversalIsExistsAny(): void
    {
        // filter[editorName]=Ada Lovelace — a many-to-many `editors` hop then the leaf.
        // Articles 1 (editors 1, 2) and 2 (editor 1) have Ada as an editor.
        self::assertSame(['1', '2'], $this->filteredIds('editorName', 'Ada Lovelace'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function theNamedKeyOverrideRespondsOnTheKeyAndTraversesThePath(): void
    {
        // filter[topAuthor]=Grace Hopper — the key (`topAuthor`) is distinct from the
        // traversed path (`author.name`). Grace authors articles 2 and 4.
        self::assertSame(['2', '4'], $this->filteredIds('topAuthor', 'Grace Hopper'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aFluentComparisonOperatorAppliesAtTheLeaf(): void
    {
        // filter[authorIdAtLeast]=2 (fluent `>=` on author.id). Author 2 (Grace) is the
        // only id >= 2, so her articles 2 and 4 match — the operator vocabulary applies
        // at the leaf exactly as a plain Where would.
        self::assertSame(['2', '4'], $this->filteredIds('authorIdAtLeast', '2'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aTraversalMatchingNothingReturnsAnEmptyCollection(): void
    {
        // A leaf value no author carries: the EXISTS-ANY is false for every row.
        self::assertSame([], $this->filteredIds('author.name', 'Nobody At All'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:fetching-includes')]
    public function aTraversalFilterDoesNotLeakIntoTheRenderedRelation(): void
    {
        // The headline composability property: the semi-join narrows WHICH parents
        // survive, never WHAT their relationship renders. filter[comments.body]=Nice
        // matches ONLY comment 2 ("Nice write-up."), which belongs to article 1
        // (comments 1, 2) — so article 1 is the sole survivor, kept by a SUBSET of its
        // comments. ?include=comments must then render article 1's FULL comment set
        // (both 1 AND 2): a fetch-join would leak the parent filter and render only the
        // matching comment 2; the correlated EXISTS does not, on either provider.
        $response = $this->handle('/articles?filter[comments.body]=Nice&include=comments');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $document = $this->decode($response);

        // The traversal kept exactly article 1.
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertCount(1, $data);
        self::assertIsArray($data[0]);
        self::assertSame('articles', $data[0]['type'] ?? null);
        self::assertSame('1', $data[0]['id'] ?? null);

        // ?include rendered article 1's FULL comment set, unaffected by the parent
        // filter that matched on only one of them.
        $included = $document['included'] ?? null;
        self::assertIsArray($included);
        $commentIds = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            if (($resource['type'] ?? null) === 'comments') {
                $id = $resource['id'] ?? null;
                self::assertIsString($id);
                $commentIds[] = $id;
            }
        }
        \sort($commentIds, \SORT_NUMERIC);
        self::assertSame(['1', '2'], $commentIds);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aMistypedValueConstrainedTraversalValueIsACleanBadRequest(): void
    {
        // filter[authorNum] is a WhereThrough whose leaf value is constrained
        // ->integer(); "banana" is not — so it is a clean 400 BEFORE the EXISTS
        // subquery runs (the same FilterValueValidator path a plain Where takes;
        // WhereThrough has no delimiter, so the single-scalar value is validated).
        $response = $this->handle('/articles?filter[authorNum]=banana');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $error = $this->firstError($this->decode($response));
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('FILTER_VALUE_INVALID', $error['code'] ?? null);
        self::assertSame(['parameter' => 'filter[authorNum]'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aValidValueConstrainedTraversalValueStillFilters(): void
    {
        // A valid integer passes validation and the traversal runs: author id 1 (Ada)
        // authors articles 1 and 3.
        self::assertSame(['1', '3'], $this->filteredIds('authorNum', '1'));
    }

    // --- the folded WhereHas / WhereDoesntHave still pass (the same EXISTS builder) ---

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function theFoldedWhereHasToManyStillMatchesOnExistence(): void
    {
        // WhereHas is now the degenerate length-1 front-end of the shared EXISTS
        // builder; it must still keep rows whose `comments` relation is non-empty:
        // articles 1, 2, 3 have comments, articles 4 and 5 do not.
        self::assertSame(['1', '2', '3'], $this->filteredIds('hasComments', 'ignored'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function theFoldedWhereDoesntHaveToManyStillMatchesTheComplement(): void
    {
        // WhereDoesntHave keeps the complement: articles 4 and 5 have no comments.
        self::assertSame(['4', '5'], $this->filteredIds('lacksComments', 'ignored'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function theFoldedWhereHasToOneStillMatchesOnExistence(): void
    {
        // The to-one existence fold: articles 1-4 have an author, article 5 is
        // authorless — a to-one and a to-many translate identically.
        self::assertSame(['1', '2', '3', '4'], $this->filteredIds('hasAuthor', 'ignored'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function theFoldedWhereDoesntHaveToOneStillMatchesTheComplement(): void
    {
        // Only article 5 is authorless.
        self::assertSame(['5'], $this->filteredIds('lacksAuthor', 'ignored'));
    }

    /**
     * Issues `GET /articles?filter[<key>]=<value>`, asserts a `200` JSON:API
     * response, and returns the primary `articles` ids in ascending numeric order —
     * so the assertion is the byte-parity *membership* of the filtered set,
     * independent of any incidental provider-default ordering.
     *
     * @return list<string>
     */
    private function filteredIds(string $key, string $value): array
    {
        $response = $this->handle('/articles?filter[' . $key . ']=' . \rawurlencode($value));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $document = $this->decode($response);
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

        \sort($ids, \SORT_NUMERIC);

        return \array_map('\strval', $ids);
    }

    /**
     * The document's first error object.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function firstError(array $document): array
    {
        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $first = $errors[0] ?? null;
        self::assertIsArray($first);

        /** @var array<string, mixed> $first */
        return $first;
    }
}
