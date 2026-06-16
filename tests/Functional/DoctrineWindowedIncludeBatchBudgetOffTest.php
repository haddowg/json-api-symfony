<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\WindowedIncludeBatchOffKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The bounded-fetch proof under `json_api.doctrine.window_functions: false` (bundle ADR
 * 0065): the per-parent bounded fallback runs real `LIMIT` push-downs and NO window
 * function — strictly better than 6a's whole-set materialise.
 */
final class DoctrineWindowedIncludeBatchBudgetOffTest extends DoctrineWindowedIncludeBatchBudgetTestCase
{
    protected static function getKernelClass(): string
    {
        return WindowedIncludeBatchOffKernel::class;
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-pagination')]
    public function theOffPathRunsBoundedLimitQueriesAndNoWindowFunction(): void
    {
        $sql = $this->windowedIncludeStatements();

        $windowStatements = \array_filter($sql, static fn(string $s): bool => \stripos($s, 'ROW_NUMBER') !== false);
        self::assertCount(0, $windowStatements, 'the off path uses no window function');

        // Each per-parent comment page is a real LIMIT push-down (bounded), never a
        // whole-set scan. SQLite renders LIMIT, so assert a bounded LIMIT ran.
        $limited = \array_filter($sql, static fn(string $s): bool => \stripos($s, 'LIMIT') !== false);
        self::assertNotEmpty($limited, "the fallback pushes a real LIMIT per parent:\n" . \implode("\n", $sql));
    }
}
