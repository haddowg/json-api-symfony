<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\DBAL\Logging\Middleware as DbalLoggingMiddleware;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\QueryCountingDoctrineKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see RelationCountConformanceTestCase} against the Doctrine provider: the
 * countable relations are counted by a pushed-down grouped `COUNT` over the
 * related association (bundle ADR 0052). It boots the
 * {@see QueryCountingDoctrineKernel}, which wraps the DBAL connection with a
 * {@see DbalLoggingMiddleware} so the batch's grouped-count behaviour is probed:
 * one count query per requested relation across the whole page, never N.
 */
final class DoctrineRelationCountTest extends RelationCountConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return QueryCountingDoctrineKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function withCountOnACollectionRunsOneGroupedCountPerRelationNotPerParent(): void
    {
        $logger = $this->logger();
        $logger->reset();

        // Five articles in the page, ?withCount over two countable relations. A
        // naive per-parent count would be 5 (parents) x 2 (relations) = 10 count
        // queries; the batch is ONE grouped count per relation = 2.
        $document = $this->fetchDocument('/articles?withCount=pagedComments,editors');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertCount(5, $data, 'the whole page of parents is rendered');

        $grouped = \array_values(\array_filter(
            $logger->statements(),
            static fn(string $sql): bool => \stripos($sql, 'GROUP BY') !== false && \stripos($sql, 'COUNT') !== false,
        ));

        self::assertCount(
            2,
            $grouped,
            \sprintf(
                "exactly one grouped COUNT per requested countable relation (2), not one per parent; ran:\n%s",
                \implode("\n", $logger->statements()),
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
