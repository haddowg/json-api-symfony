<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\DataProvider\RelatedIncludeBatcher;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\FlattenDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Doctrine query-budget PROOF for the flattened-attribute (`on()`) eager load
 * (bundle ADR 0085): a `/books` collection read flattening `authorName`/`editorName`
 * over three books with DISTINCT lazy `author`/`editor` associations would, without
 * the eager batch, trigger one `SELECT … FROM flatten_author WHERE id = ?` PER book
 * PER backing relation (the per-row N+1 the flattened read introduces). The eager
 * loader collapses each backing relation to ONE batched `WHERE id IN (…)` load (so the
 * author-table reads are O(1) in the book count — one per backing relation, NOT one
 * per row) — and the rendered document is byte-identical to the un-batched one
 * (eager-loading changes only the query plan, never the document).
 *
 * The witness toggles the {@see RelatedIncludeBatcher} off to reveal the N+1, then on
 * to prove the collapse — the disable seam is the same one the include-batch witnesses
 * use. The multi-hop `on('author.country')` then collapses its SECOND hop to one
 * `flatten_country WHERE id IN (…)` too — O(depth), never per-row.
 */
final class DoctrineFlattenQueryBudgetTest extends JsonApiFunctionalTestCase
{
    use SeedsFlatten;

    private const string BASE_URI = 'https://example.test';

    #[Test]
    #[Group('spec:fetching')]
    public function theFlattenedReadN1IsRevealedWhenBatchingIsDisabled(): void
    {
        // Cold read with the eager batch OFF: the flattened `authorName` forces a lazy
        // per-book author load, so three books fingerprint THREE single-id author
        // SELECTs (`WHERE … id = ?`, not `IN`). This is the N+1 the batch removes.
        $this->batcher()->disable();
        try {
            $authorLoads = $this->authorSelects($this->captureBookCollection());
        } finally {
            $this->batcher()->enable();
        }

        $perRowLoads = \array_filter($authorLoads, static fn(string $sql): bool => !\str_contains(\strtolower($sql), 'in ('));
        self::assertGreaterThanOrEqual(
            3,
            \count($perRowLoads),
            "with batching off, the flattened read must N+1 (one author SELECT per book); statements were:\n"
                . \implode("\n", $authorLoads),
        );
    }

    #[Test]
    #[Group('spec:fetching')]
    public function theFlattenedReadIsCollapsedToOneBatchedLoadWhenBatchingIsOn(): void
    {
        // The eager batch ON: each author-backed flattened relation (`author` and the
        // VISIBLE `editor`) loads its distinct authors in ONE batched `WHERE id IN (…)`
        // query — O(1) in the book count (one IN(…) per backing relation, NOT one per
        // row), with NO per-row author SELECT.
        $authorLoads = $this->authorSelects($this->captureBookCollection());

        $batched = \array_filter($authorLoads, static fn(string $sql): bool => \str_contains(\strtolower($sql), 'in ('));
        $perRow = \array_filter($authorLoads, static fn(string $sql): bool => !\str_contains(\strtolower($sql), 'in ('));

        // Two author-backed eager relations (`author`, `editor`), each ONE batched
        // load — bounded by the number of backing relations, never the page size.
        self::assertCount(
            2,
            $batched,
            \sprintf("each author-backed flattened relation must batch in ONE IN(…) query; matched %d:\n%s", \count($batched), \implode("\n", $authorLoads)),
        );
        self::assertSame(
            [],
            \array_values($perRow),
            "the eager batch must leave NO per-row author SELECT:\n" . \implode("\n", $perRow),
        );
    }

    #[Test]
    #[Group('spec:fetching')]
    public function theMultiHopChainLoadsInODepthWithNoPerRowSelectAtTheSecondHop(): void
    {
        // The multi-hop `on('author.country')` walks each book's author (hop 1), then
        // each author's country (hop 2). With the batch ON the SECOND hop collapses to
        // ONE `flatten_country WHERE id IN (…)` query — O(depth), NOT one country SELECT
        // per author row.
        $countryLoads = $this->countrySelects($this->captureBookCollection());

        $batched = \array_filter($countryLoads, static fn(string $sql): bool => \str_contains(\strtolower($sql), 'in ('));
        $perRow = \array_filter($countryLoads, static fn(string $sql): bool => !\str_contains(\strtolower($sql), 'in ('));

        self::assertCount(
            1,
            $batched,
            \sprintf("the multi-hop author.country second hop must batch in ONE IN(…) query; matched %d:\n%s", \count($batched), \implode("\n", $countryLoads)),
        );
        self::assertSame(
            [],
            \array_values($perRow),
            "the multi-hop eager walk must leave NO per-row country SELECT:\n" . \implode("\n", $perRow),
        );
    }

    #[Test]
    #[Group('spec:fetching')]
    public function theMultiHopChainN1IsRevealedWhenBatchingIsDisabled(): void
    {
        // Cold read with the batch OFF: the multi-hop `authorCountry` lazy-walks each
        // author's `country` proxy, so the three authored books fingerprint per-row
        // single-id country SELECTs — the multi-hop N+1 the second-hop batch removes.
        $this->batcher()->disable();
        try {
            $countryLoads = $this->countrySelects($this->captureBookCollection());
        } finally {
            $this->batcher()->enable();
        }

        $perRow = \array_filter($countryLoads, static fn(string $sql): bool => !\str_contains(\strtolower($sql), 'in ('));
        self::assertNotEmpty(
            $perRow,
            "with batching off, the multi-hop author.country read must lazy-load per row; statements were:\n"
                . \implode("\n", $countryLoads),
        );
    }

    #[Test]
    #[Group('spec:fetching')]
    public function theDocumentIsByteIdenticalWithAndWithoutBatching(): void
    {
        // Eager-loading changes only the query plan, never the document: a `/books` read
        // is identical with the batch on and off.
        $on = (string) $this->handle(self::BASE_URI . '/books?sort=title')->getContent();

        $this->batcher()->disable();
        try {
            $off = (string) $this->handle(self::BASE_URI . '/books?sort=title')->getContent();
        } finally {
            $this->batcher()->enable();
        }

        self::assertSame($on, $off, 'the flattened read renders identically with and without the eager batch');
    }

    /**
     * Captures the SQL of a `/books` collection read (which flattens `authorName` over
     * the page) through the query-counting logger.
     *
     * @return list<string>
     */
    private function captureBookCollection(): array
    {
        $logger = $this->logger();
        $logger->reset();

        $response = $this->handle(self::BASE_URI . '/books?sort=title');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $logger->statements();
    }

    /**
     * SELECTs that read the `flatten_author` table.
     *
     * @param list<string> $statements
     *
     * @return list<string>
     */
    private function authorSelects(array $statements): array
    {
        return \array_values(\array_filter(
            $statements,
            static function (string $sql): bool {
                $lower = \strtolower($sql);

                return \str_starts_with(\ltrim($lower), 'select') && \str_contains($lower, 'from flatten_author');
            },
        ));
    }

    /**
     * SELECTs that read the `flatten_country` table (the multi-hop second hop).
     *
     * @param list<string> $statements
     *
     * @return list<string>
     */
    private function countrySelects(array $statements): array
    {
        return \array_values(\array_filter(
            $statements,
            static function (string $sql): bool {
                $lower = \strtolower($sql);

                return \str_starts_with(\ltrim($lower), 'select') && \str_contains($lower, 'from flatten_country');
            },
        ));
    }

    private function batcher(): RelatedIncludeBatcher
    {
        $batcher = static::getContainer()->get('test.flatten_include_batcher');
        self::assertInstanceOf(RelatedIncludeBatcher::class, $batcher);

        return $batcher;
    }

    private function logger(): QueryCountingLogger
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);

        return $logger;
    }

    protected static function getKernelClass(): string
    {
        return FlattenDoctrineTestKernel::class;
    }
}
