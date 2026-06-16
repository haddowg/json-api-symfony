<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\WindowedIncludeBatchOnKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The bounded-fetch proof under `json_api.doctrine.window_functions: true` (bundle ADR
 * 0065): the windowed include runs ONE native ROW_NUMBER statement, bounded to ~limit
 * rows per parent.
 */
final class DoctrineWindowedIncludeBatchBudgetOnTest extends DoctrineWindowedIncludeBatchBudgetTestCase
{
    protected static function getKernelClass(): string
    {
        return WindowedIncludeBatchOnKernel::class;
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-pagination')]
    public function theNativeOnPathRunsOneBoundedRowNumberStatementPerRelation(): void
    {
        $sql = $this->windowedIncludeStatements();

        $windowStatements = \array_values(\array_filter(
            $sql,
            static fn(string $s): bool => \stripos($s, 'ROW_NUMBER') !== false,
        ));

        // The batch runs ONE native ROW_NUMBER statement PER windowed to-many relation
        // (O(relations)), NEVER one per parent (O(M*relations)) — the decisive #55/6a fix.
        // The article declares 6 windowable to-many relations and the page is M=3 parents;
        // a per-parent loop would be ~18 windowing statements. A generous ceiling of 8 (one
        // per relation + headroom) proves the budget did not scale with the 3 parents.
        self::assertLessThanOrEqual(
            8,
            \count($windowStatements),
            "windowing is O(relations), not O(M*relations); a per-parent loop over 3 parents would blow past this:\n" . \implode("\n", $sql),
        );

        // The pinnedComments window (scoped on its unique inverse FK in the inner DQL the
        // native ROW_NUMBER wraps — bundle ADR 0066) is BOUNDED to rn <= :limit and carries
        // the real per-parent total on the same scan — the bound that kills the 6a whole-set
        // over-fetch. The inner DQL projects the FK as a generated scalar alias the outer
        // window partitions by (w.sclr_N), so the window is matched by its inner FK scope,
        // not a physical partition column.
        $pinnedWindow = $this->windowScopedByFk($windowStatements, 'pinned_article_id');
        self::assertNotNull($pinnedWindow, "a ROW_NUMBER window wraps the pinnedComments inverse-FK inner query:\n" . \implode("\n", $sql));
        self::assertMatchesRegularExpression(
            '/PARTITION BY w\.\w+/i',
            $pinnedWindow,
            'the outer window partitions by the generated parent-discriminator alias read off the inner RSM',
        );
        self::assertMatchesRegularExpression(
            '/jsonapi_rn\s*<=/i',
            $pinnedWindow,
            'the outer wrap bounds rows to rn <= :limit (the bound that kills the 6a whole-set over-fetch)',
        );
        self::assertStringContainsString('COUNT(*) OVER', $pinnedWindow, 'the real per-parent total rides the same scan');
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-filtering')]
    public function theFilteredWindowedIncludeRunsOneBoundedNativeQuery(): void
    {
        // A FILTERED windowed include (filter[bodyContains]=comment-4 + sort + page) folds
        // onto the SAME native ROW_NUMBER path as the unfiltered one (bundle ADR 0066): the
        // inner scoped query carries the relatedQuery filter through the #1 DQL filter
        // executor (CriteriaApplier + DoctrineFilterHandler), then is wrapped for the
        // window — so it is ONE bounded native query, NOT M per-parent queries.
        $sql = $this->filteredWindowedIncludeStatements();

        $windowStatements = \array_values(\array_filter(
            $sql,
            static fn(string $s): bool => \stripos($s, 'ROW_NUMBER') !== false,
        ));

        // Still O(relations), never O(M*relations): a filtered windowed include over 3
        // parents must NOT fan into a per-parent loop. The same generous ceiling of 8.
        self::assertLessThanOrEqual(
            8,
            \count($windowStatements),
            "a filtered windowed include stays O(relations) on the native path, not a per-parent loop:\n" . \implode("\n", $sql),
        );

        // The pinnedComments window for the FILTERED include is identified by its inner
        // inverse-FK scope and, decisively, carries the DQL filter handler's LIKE predicate
        // INSIDE the wrapped inner query — proving the native path reuses the #1 DQL filter
        // executor (no native filter translator).
        $pinnedWindow = $this->windowScopedByFk($windowStatements, 'pinned_article_id');
        self::assertNotNull($pinnedWindow, "a ROW_NUMBER window wraps the filtered pinnedComments inner query:\n" . \implode("\n", $sql));
        self::assertMatchesRegularExpression(
            "/LOWER\\([^)]*\\)\\s+LIKE[^)]*ESCAPE/i",
            $pinnedWindow,
            'the wrapped inner query carries the DQL filter handler\'s LIKE predicate — the #1 executor, not a native translator',
        );
        self::assertMatchesRegularExpression(
            '/jsonapi_rn\s*<=/i',
            $pinnedWindow,
            'the filtered window is bounded to rn <= :limit (one bounded native query, never an over-fetch)',
        );
        self::assertStringContainsString('COUNT(*) OVER', $pinnedWindow, 'the FILTERED per-parent total rides the same scan');
    }

    /**
     * The first native ROW_NUMBER window whose WRAPPED inner DQL scopes by `$fkColumn`
     * (`<fkColumn> IN`), or null — the new DQL-wrap shape partitions by a generated scalar
     * alias, so the pinned window is identified by its inner inverse-FK scope (bundle ADR
     * 0066), not a physical partition column name.
     *
     * @param list<string> $statements
     */
    private function windowScopedByFk(array $statements, string $fkColumn): ?string
    {
        foreach ($statements as $statement) {
            if (\stripos($statement, $fkColumn . ' IN') !== false) {
                return $statement;
            }
        }

        return null;
    }
}
