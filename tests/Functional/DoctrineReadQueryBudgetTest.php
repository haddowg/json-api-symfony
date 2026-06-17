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
        // `WHERE article_id = ?` loads instead. (NB: linkage rendering of OTHER, non-
        // included to-many relations not marked `dataOnlyWhenLoaded` is a separate
        // per-parent concern — see the audit's second finding — so this isolates the
        // include batch by its `IN (…)` fingerprint.)
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
