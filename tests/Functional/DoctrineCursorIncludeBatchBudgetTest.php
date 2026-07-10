<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CursorIncludeBatchLoggingKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The SQL PROOF for the CURSOR (keyset) windowed-include batch (bundle ADR 0118): a
 * cursor-resolved COLLECTION include over `cursorShelves` → `widgets` (an owning-side
 * ManyToMany, the join-table window shape) inspects the {@see QueryCountingLogger}'s captured
 * SQL to prove the N per-parent keyset queries collapsed to ONE bounded `ROW_NUMBER()` window
 * per relation — the cursor twin of {@see DoctrineWindowedIncludeBatchBudgetOnTest}.
 *
 * Byte-identical rendered output against the in-memory witness is the
 * {@see CursorIncludeBatchConformanceTestCase} suites' concern; here the concern is the SQL
 * shape and the statement count.
 */
final class DoctrineCursorIncludeBatchBudgetTest extends JsonApiFunctionalTestCase
{
    use SeedsDoctrineCursorShelves;

    private const string BASE_URI = 'https://example.test';

    /** Negotiates the Relationship-Queries (windowing) profile that windows an included to-many to page 1. */
    private const string PROFILE_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

    protected static function getKernelClass(): string
    {
        return CursorIncludeBatchLoggingKernel::class;
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-pagination')]
    public function aCursorResolvedIncludeCollapsesToOneBoundedRowNumberWindow(): void
    {
        // A cursor include over the whole page of shelves sorted on the NULLABLE `priority`.
        $windowStatements = \array_values(\array_filter(
            $this->captureStatements('/cursorShelves?include=widgets&relatedQuery[widgets][sort]=priority'),
            static fn(string $s): bool => \stripos($s, 'ROW_NUMBER') !== false,
        ));

        // The N→1 collapse: exactly ONE ROW_NUMBER window for the whole page of shelves, NEVER
        // one keyset query per parent — the decisive proof. (The page is 3 shelves; a per-parent
        // loop would run 3 keyset SELECTs.)
        self::assertCount(
            1,
            $windowStatements,
            "a cursor-resolved include is ONE bounded ROW_NUMBER window, not a per-parent loop:\n" . \implode("\n", $windowStatements),
        );

        $window = $windowStatements[0];

        // The join-table shape partitions by the generated parent-discriminator scalar alias
        // read off the inner RSM (w.sclr_N), exactly as the offset m2m window does.
        self::assertMatchesRegularExpression(
            '/PARTITION BY w\.\w+/i',
            $window,
            'the outer window partitions by the generated parent-discriminator alias',
        );

        // The forced NULL=largest keyset ordering term — the `CASE … IS NULL …` #21 feared —
        // composed VERBATIM inside `ROW_NUMBER() OVER (… ORDER BY …)`, over the generated sort
        // scalar alias. This is the disproven-blocker proof: the SAME raw term the offset window
        // has always emitted, carrying no bindings.
        self::assertMatchesRegularExpression(
            '/CASE WHEN w\.\w+ IS NULL THEN 1 ELSE 0 END ASC/i',
            $window,
            'the keyset CASE WHEN … IS NULL … term composes inside the ROW_NUMBER OVER clause',
        );

        // A cursor page is count-free by definition, so the window is bounded by the count-free
        // `jsonapi_rn <= :cap` probe (limit + 1) and carries NO `COUNT(*) OVER` total.
        self::assertMatchesRegularExpression(
            '/jsonapi_rn\s*<=/i',
            $window,
            'the outer wrap bounds rows to jsonapi_rn <= limit + 1 (the count-free hasMore probe)',
        );
        self::assertStringNotContainsStringIgnoringCase(
            'COUNT(*) OVER',
            $window,
            'a cursor page is count-free: no COUNT(*) OVER total rides the scan',
        );
    }

    /**
     * Captures the SQL the request at `$path` runs through the {@see QueryCountingLogger}.
     *
     * @return list<string>
     */
    private function captureStatements(string $path): array
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);
        $logger->reset();

        $response = $this->handle(self::BASE_URI . $path, extraServer: ['HTTP_ACCEPT' => self::PROFILE_ACCEPT]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $logger->statements();
    }
}
