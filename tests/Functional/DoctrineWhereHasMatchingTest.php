<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ConstrainedFilterDoctrineTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The **Doctrine-only** acceptance suite for the {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\Filter\WhereHasMatching}
 * escape hatch (bundle ADR 0069): a relationship-existence filter narrowed by an
 * author-supplied inner predicate — a structured {@see \Doctrine\Common\Collections\Criteria}
 * (the primary surface, applied with `addCriteria` on the related root) or a raw
 * subquery closure parameterised by the request value (the deep hatch). Both feed
 * the SAME correlated `EXISTS` subquery as `WhereThrough`/`WhereHas`, rooted on the
 * related entity.
 *
 * There is **no in-memory witness**: the filter lives in the Doctrine namespace and
 * is recognised only by the Doctrine handler, so it is declared only on the Doctrine
 * resource. On the in-memory provider the same `filter[<key>]` is undeclared, which
 * is the unrecognised-filter `400` boundary (exactly like the pivot-filter prefix) —
 * asserted by {@see InMemoryWhereHasMatchingBoundaryTest} so the boundary is never a
 * silent non-match.
 *
 * With the canonical fixtures the `editors` (many-to-many) linkage is articles
 * 1→[author 1, 2], 2→[author 1], 3→[author 2]; author 1 = "Ada Lovelace", author 2
 * = "Grace Hopper".
 */
final class DoctrineWhereHasMatchingTest extends JsonApiFunctionalTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return ConstrainedFilterDoctrineTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aCriteriaSurfaceNarrowsTheRelatedRoot(): void
    {
        // filter[editorNamed] — a Criteria eq('name', 'Ada Lovelace') on the related
        // `editors` root. Articles 1 (editors Ada, Grace) and 2 (editor Ada) have Ada
        // as an editor. The request value is ignored (the author owns the predicate).
        self::assertSame(['1', '2'], $this->filteredIds('editorNamed', 'ignored'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aCriteriaSurfaceComposesAnOrPredicate(): void
    {
        // filter[editorEither] — Criteria orX(eq Ada, eq Grace): the multi-value OR the
        // portable WhereThrough vocabulary cannot express. Articles 1, 2 and 3 each
        // have at least one editor named Ada or Grace.
        self::assertSame(['1', '2', '3'], $this->filteredIds('editorEither', 'ignored'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aClosureSurfaceIsParameterisedByTheRequestValue(): void
    {
        // filter[editorNameLike]=Grace — the deep-hatch closure adds a LIKE on the
        // editor name bound to the request value. Articles 1 (Grace among its editors)
        // and 3 (editor Grace) match.
        self::assertSame(['1', '3'], $this->filteredIds('editorNameLike', 'Grace'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aClosureSurfaceTracksADifferentRequestValue(): void
    {
        // The same closure filter with a different request value: "Ada" now selects
        // articles 1 and 2 — proving the closure binds the request value, not a fixed
        // literal.
        self::assertSame(['1', '2'], $this->filteredIds('editorNameLike', 'Ada'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function twoMatchingFiltersOnOneQueryDoNotCollide(): void
    {
        // Two WhereHasMatching filters on one request — each builds its own EXISTS
        // subquery and lifts its bound parameters onto the outer query, so the
        // placeholders cannot collide. editorEither = {1,2,3} AND editorNameLike=Grace
        // = {1,3} → {1,3}.
        $response = $this->handle('/articles?filter[editorEither]=ignored&filter[editorNameLike]=Grace');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['1', '3'], $this->idsOf($response));
    }

    /**
     * Issues `GET /articles?filter[<key>]=<value>`, asserts a `200`, and returns the
     * primary `articles` ids in ascending numeric order.
     *
     * @return list<string>
     */
    private function filteredIds(string $key, string $value): array
    {
        $response = $this->handle('/articles?filter[' . $key . ']=' . \rawurlencode($value));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->idsOf($response);
    }

    /**
     * The primary `articles` ids of a `200` response, ascending numeric.
     *
     * @return list<string>
     */
    private function idsOf(\Symfony\Component\HttpFoundation\Response $response): array
    {
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
}
