<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\QueryCountingDoctrineKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The query-budget witness for the batched windowed-include path (bundle ADR 0061):
 * under the Relationship Queries profile, a collection include that windows N
 * relations over M parents issues ONE
 * {@see \haddowg\JsonApiBundle\DataProvider\DataProviderInterface::fetchRelatedCollectionBatch()}
 * per relation — so the windowed-include statement count is O(N), NOT the ~2*M*N the
 * retired per-parent loop ran (the #55 fix made decisive). It boots the
 * {@see QueryCountingDoctrineKernel}, whose DBAL logging middleware counts the SQL
 * statements the windowed-include request executes.
 */
final class DoctrineWindowedIncludeBudgetTest extends JsonApiFunctionalTestCase
{
    use SeedsDoctrineRelationships;

    private const string BASE_URI = 'https://example.test';

    private const string PROFILE_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

    protected static function getKernelClass(): string
    {
        return QueryCountingDoctrineKernel::class;
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-relationships')]
    public function aWindowedCollectionIncludeRunsOneBatchPerRelationNotPerParent(): void
    {
        $logger = $this->logger();
        $logger->reset();

        // GET /articles (M = 5 parents) including TWO windowed to-many relations under
        // the profile (each with a relatedQuery sort, so each is windowed to page 1):
        //  - editors      — a countable many-to-many (the pair + id-load batch shape);
        //  - pagedComments — a countable inverse-FK to-many (the one-query batch shape).
        // The retired per-parent loop re-drove fetchRelatedCollection M x N times, each a
        // count + a page = ~2*M*N = 20 statements just for the windowing. The batch runs
        // ONE fetchRelatedCollectionBatch per relation over the whole page, so the
        // windowing cost is O(N): pagedComments = 1 query, editors = 2 (pair scan + the
        // single distinct-related id-load) = 3 — independent of M.
        $response = $this->handle(
            self::BASE_URI . '/articles?include=editors,pagedComments'
                . '&relatedQuery[editors][sort]=name&relatedQuery[pagedComments][sort]=body',
            extraServer: ['HTTP_ACCEPT' => self::PROFILE_ACCEPT],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->decode($response);
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertCount(5, $data, 'the whole page of parents is rendered');

        $total = \count($logger->statements());

        // The decisive O(N) bound: the WHOLE profiled request (primary fetch + the two
        // batched windowed relations + their id-load) stays well under the ~2*M*N = 20
        // statements the per-parent loop alone would have run for the windowing. A
        // generous ceiling (the primary fetch, schema/connection probes, and at most a
        // handful of batch statements) of 12 still proves the budget did not scale with
        // the 5 parents — a per-parent loop would have blown straight past it.
        self::assertLessThanOrEqual(
            12,
            $total,
            \sprintf(
                "windowing N=2 relations over M=5 parents must be O(N), not ~2*M*N=20; ran %d statements:\n%s",
                $total,
                \implode("\n", $logger->statements()),
            ),
        );
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-filtering')]
    public function aCollectionIncludeToOneNullingRunsOneBatchedMatchNotOnePerParent(): void
    {
        $logger = $this->logger();
        $logger->reset();

        // GET /articles (M = 5 parents) including the to-one `author`, with a relatedQuery
        // filter that excludes some authors (only Ada Lovelace's articles keep their
        // author). The to-one nulling path (bundle ADR 0068) must match all parents'
        // author targets in ONE `relatedToOneMatchesBatch` — a single
        // `SELECT id … FROM author WHERE id IN (…) AND name = …` — NOT a per-parent probe
        // (~M queries). The query count is constant in M.
        $response = $this->handle(
            self::BASE_URI . '/articles?include=author&relatedQuery[author][filter][name]=Ada%20Lovelace',
            extraServer: ['HTTP_ACCEPT' => self::PROFILE_ACCEPT],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->decode($response);
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertCount(5, $data, 'the whole page of parents is rendered');

        // The decisive guard: the to-one nulling fingerprint — a SELECT against the
        // `author` table with an `IN (…)` target set AND a `name = ?` filter PREDICATE
        // (distinct from the include-preload's `name AS …` projection) — appears EXACTLY
        // ONCE (one batched match over the whole page), never once per parent. A
        // per-parent probe (the N+1 the batch replaced) would have run 5.
        $nullingProbes = \array_filter(
            $logger->statements(),
            static fn(string $sql): bool => \str_contains($sql, 'FROM author')
                && \str_contains($sql, ' IN (')
                && \str_contains($sql, '.name = '),
        );
        self::assertCount(
            1,
            $nullingProbes,
            \sprintf(
                "the to-one nulling must run ONE batched match over the page, not one per parent; matched:\n%s",
                \implode("\n", $nullingProbes),
            ),
        );

        // And the WHOLE request stays under a constant ceiling independent of the 5
        // parents (the primary fetch + the include preload + the single nulling batch +
        // schema/connection probes) — a per-parent probe would have blown past it.
        $total = \count($logger->statements());
        self::assertLessThanOrEqual(
            12,
            $total,
            \sprintf(
                "to-one nulling over M=5 parents must be O(1) nulling queries, not O(M); ran %d statements:\n%s",
                $total,
                \implode("\n", $logger->statements()),
            ),
        );
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-filtering')]
    public function aRelatedQueryFilterOnANotIncludedLazyToManyIssuesNoWindowQuery(): void
    {
        $logger = $this->logger();
        $logger->reset();

        // GET /articles?relatedQuery[lazyComments][filter][body]=First! under the profile,
        // WITHOUT including lazyComments. lazyComments is a lazy (links-only-by-default)
        // to-many backed by the `featured_article` association — it does not render data
        // this request, so it must NOT be windowed (bundle ADR 0086): no
        // fetchRelatedCollectionBatch, and so ZERO queries against the `featured_article`
        // column. Before the gate this filtered window ran ONE batch query per such
        // relation AND leaked the filtered page onto the lazy property.
        $response = $this->handle(
            self::BASE_URI . '/articles?relatedQuery[lazyComments][filter][body]=First%21',
            extraServer: ['HTTP_ACCEPT' => self::PROFILE_ACCEPT],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        // The decisive witness: NO SQL statement SCOPES the `featured_article` association
        // (the lazyComments backing FK) in a WHERE … IN predicate. The window's batch fetch
        // is the only path that would root a fetch on `featured_article_id IN (…)`, so its
        // absence proves the not-included lazy to-many was not windowed. (A `comments`-backed
        // window SELECTs `featured_article_id` as a projected column but scopes on
        // `article_id IN (…)`, so the predicate match avoids that false positive.)
        $featuredWindowQueries = \array_values(\array_filter(
            $logger->statements(),
            static fn(string $sql): bool => \str_contains($sql, 'featured_article_id IN ('),
        ));
        self::assertCount(
            0,
            $featuredWindowQueries,
            \sprintf(
                "a not-included lazy to-many must issue ZERO window queries; matched:\n%s",
                \implode("\n", $featuredWindowQueries),
            ),
        );
    }

    private function logger(): QueryCountingLogger
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);

        return $logger;
    }
}
