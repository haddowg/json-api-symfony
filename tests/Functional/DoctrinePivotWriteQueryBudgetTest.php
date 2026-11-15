<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\QueryCountingDoctrineKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use PHPUnit\Framework\Attributes\Test;

/**
 * A pivot write reads its members' rows once: the pivot's pre-validation, which needs to
 * know which members the scope hides, and the write's own existence check share one
 * batched read of the related type.
 */
final class DoctrinePivotWriteQueryBudgetTest extends JsonApiFunctionalTestCase
{
    use SeedsDoctrinePivot;

    protected static function getKernelClass(): string
    {
        return QueryCountingDoctrineKernel::class;
    }

    #[Test]
    public function aPivotReplaceReadsItsMembersOnce(): void
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);
        $logger->reset();

        $response = $this->handle('https://example.test/playlists/1/relationships/tracks', 'PATCH', ['data' => [
            ['type' => 'tracks', 'id' => '1', 'meta' => ['pivot' => ['position' => 3]]],
            ['type' => 'tracks', 'id' => '2', 'meta' => ['pivot' => ['position' => 2]]],
            ['type' => 'tracks', 'id' => '3', 'meta' => ['pivot' => ['position' => 1]]],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $lookups = \array_values(\array_filter(
            $logger->statements(),
            static fn(string $sql): bool => \preg_match('/^SELECT .* FROM track \w+ WHERE \w+\.id IN \(/i', \ltrim($sql)) === 1,
        ));
        self::assertCount(1, $lookups, \implode("\n", $logger->statements()));
    }
}
