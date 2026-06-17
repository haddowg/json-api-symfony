<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\QueryCountingDoctrineKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Query-budget witnesses for the READ paths the windowed-include / `?withCount`
 * budget suites do not already cover (pre-v1 N+1 audit, 2026-06-17):
 *  - a plain (non-windowed) collection `?include` batches ONE related load per
 *    relation across the whole page, not one per parent;
 *  - a `WhereHas` existence filter compiles to a correlated `EXISTS` subquery — the
 *    `comment` table is never SELECTed standalone (no per-row probe, no fetch-join);
 *  - a paginated collection issues exactly one `COUNT` and one primary `SELECT`.
 *
 * Boots the {@see QueryCountingDoctrineKernel}; assertions read the SQL back off the
 * {@see QueryCountingLogger}.
 */
final class DoctrineReadQueryBudgetTest extends JsonApiFunctionalTestCase
{
    use SeedsDoctrineRelationships;

    private const string BASE_URI = 'https://example.test';

    protected static function getKernelClass(): string
    {
        return QueryCountingDoctrineKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aPlainCollectionIncludeBatchesOneLoadPerRelationNotPerParent(): void
    {
        $logger = $this->logger();
        $logger->reset();

        // GET /articles (5 parents) ?include=comments — a plain compound document. The
        // preloader batches the INCLUDED comments in ONE query across the page
        // (SELECT … FROM comment WHERE article_id IN (…)), so the include load is O(1)
        // in the parent count — a per-parent preloader regression would emit five
        // `WHERE article_id = ?` loads instead. (Since the lazy-by-default flip, core
        // ADR 0067, a non-included to-many renders links-only and forces no per-parent
        // load at all — see the Finding-2 witness below — so this isolates the include
        // batch by its `IN (…)` fingerprint.)
        $response = $this->handle(self::BASE_URI . '/articles?include=comments');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $includeBatch = \array_filter(
            $this->selectsFrom($logger, 'comment'),
            static fn(string $sql): bool => \str_contains(\strtolower($sql), 'in ('),
        );
        self::assertCount(
            1,
            $includeBatch,
            \sprintf(
                "the included comments must load in ONE batched IN(…) query, not one per parent; matched %d:\n%s",
                \count($includeBatch),
                \implode("\n", $includeBatch),
            ),
        );
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aWhereHasFilterCompilesToACorrelatedExistsNotAPerRowProbe(): void
    {
        $logger = $this->logger();
        $logger->reset();

        // GET /articles?filter[hasComments]=1 — the existence filter must compile to a
        // correlated EXISTS subquery folded into the article query (never a fetch-join
        // that multiplies rows, never a per-article probe to evaluate the predicate).
        // So the primary article SELECT carries an `EXISTS (… FROM comment …)`. (The
        // separate per-parent comment loads on the result are linkage rendering — the
        // audit's second finding — not the filter, so this asserts the filter shape
        // directly: an EXISTS subquery referencing the comment table.)
        $response = $this->handle(self::BASE_URI . '/articles?filter[hasComments]=1&sort=title');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $existsOverComments = \array_filter(
            $this->statementsContaining($logger, 'exists'),
            static fn(string $sql): bool => \str_contains(\strtolower($sql), 'from comment'),
        );
        self::assertNotEmpty(
            $existsOverComments,
            "the existence filter must compile to an EXISTS subquery over the comment table; statements were:\n"
                . \implode("\n", $logger->statements()),
        );
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aLazyToManyOnACollectionForcesNoPerParentLinkageLoad(): void
    {
        $logger = $this->logger();
        $logger->reset();

        // GET /articles (5 parents) — a plain collection, no `?include`. The Finding-2
        // fix (core ADR 0067): a to-many is lazy by default, so a non-included to-many
        // renders its links only and never initialises its collection just to serialize
        // identifiers. The `pinnedComments` relation is an inverse one-to-many keyed by
        // the UNIQUE `pinned_article_id` FK on the comment table, so a per-parent
        // linkage load would fingerprint as `… FROM comment WHERE pinned_article_id = ?`
        // — five of them before the flip. After it, there are zero.
        $response = $this->handle(self::BASE_URI . '/articles');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        // Fingerprint the WHERE predicate (`pinned_article_id = …`), not the column in
        // the SELECT list — the eager `comments` baseline (FK `article_id`) selects the
        // `pinned_article_id` column too, but only a pinnedComments load filters on it.
        $perParentPinnedLoads = \array_filter(
            $this->selectsFrom($logger, 'comment'),
            static fn(string $sql): bool => \str_contains(\strtolower($sql), 'pinned_article_id ='),
        );
        self::assertSame(
            [],
            \array_values($perParentPinnedLoads),
            \sprintf(
                "a lazy to-many must force no per-parent linkage load; matched %d:\n%s",
                \count($perParentPinnedLoads),
                \implode("\n", $perParentPinnedLoads),
            ),
        );
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aPaginatedCollectionRunsExactlyOneCountAndOnePrimarySelect(): void
    {
        $logger = $this->logger();
        $logger->reset();

        $response = $this->handle(self::BASE_URI . '/articles?page[size]=2');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $counts = $this->statementsContaining($logger, 'count(');
        self::assertCount(
            1,
            $counts,
            \sprintf("pagination must run exactly one COUNT; ran %d:\n%s", \count($counts), \implode("\n", $counts)),
        );

        $primarySelects = \array_filter(
            $this->selectsFrom($logger, 'article'),
            static fn(string $sql): bool => !\str_contains(\strtolower($sql), 'count('),
        );
        self::assertCount(
            1,
            $primarySelects,
            \sprintf("the page must run exactly one primary article SELECT; ran %d:\n%s", \count($primarySelects), \implode("\n", $primarySelects)),
        );
    }

    /**
     * SELECT statements that read the given table.
     *
     * @return list<string>
     */
    private function selectsFrom(QueryCountingLogger $logger, string $table): array
    {
        return \array_values(\array_filter(
            $logger->statements(),
            static function (string $sql) use ($table): bool {
                $lower = \strtolower($sql);

                return \str_starts_with(\ltrim($lower), 'select') && \str_contains($lower, 'from ' . $table);
            },
        ));
    }

    /**
     * Statements whose lowercased SQL contains the given needle.
     *
     * @return list<string>
     */
    private function statementsContaining(QueryCountingLogger $logger, string $needle): array
    {
        return \array_values(\array_filter(
            $logger->statements(),
            static fn(string $sql): bool => \str_contains(\strtolower($sql), $needle),
        ));
    }

    private function logger(): QueryCountingLogger
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);

        return $logger;
    }
}
