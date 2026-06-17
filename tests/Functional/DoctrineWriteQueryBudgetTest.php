<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\QueryCountingDoctrineKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The query-budget witness for the WRITE path (pre-v1 N+1 audit, 2026-06-17).
 *
 * For a **many-to-many** relation the parent owns (the join-table side), the
 * persister resolves each incoming linkage id with `EntityManager::getReference()` —
 * a lazy proxy that issues NO SELECT — and the join row inserts from the proxy's
 * known id, so the SELECT budget is independent of the linkage size. These tests lock
 * that property.
 *
 * For an **inverse one-to-many** (the foreign key lives on the related/child entity,
 * e.g. `comments`), the persister sets the owning-side FK on each incoming member
 * (`DoctrineDataPersister::attachOwner()` → `$member->{$owningField} = $owner`), which
 * **initialises the proxy — one SELECT per incoming id**. That is a write-side N+1 the
 * audit's first pass missed; it is recorded by the skipped test below (un-skip when
 * the persister stops loading each member to re-point its FK).
 *
 * Boots the {@see QueryCountingDoctrineKernel}; assertions read the SELECT statements
 * back off the {@see QueryCountingLogger}.
 */
final class DoctrineWriteQueryBudgetTest extends JsonApiFunctionalTestCase
{
    use SeedsDoctrineRelationships;

    private const string BASE_URI = 'https://example.test';

    protected static function getKernelClass(): string
    {
        return QueryCountingDoctrineKernel::class;
    }

    #[Test]
    #[Group('spec:crud')]
    public function aManyToManyCreateResolvesLinkageWithoutPerIdSelects(): void
    {
        $logger = $this->logger();
        $logger->reset();

        // POST a new article naming both authors as `editors` (a many-to-many the
        // article owns via the `article_editors` join table). Each id is a
        // `getReference()` proxy and the join row inserts from its known id, so NO
        // SELECT against the `author` table runs to resolve the linkage. A find()/load
        // per id would SELECT the author table twice.
        $response = $this->handle(self::BASE_URI . '/articles', 'POST', [
            'data' => [
                'type' => 'articles',
                'attributes' => ['title' => 'Budget probe', 'body' => 'x', 'category' => 'news'],
                'relationships' => [
                    'editors' => ['data' => [
                        ['type' => 'authors', 'id' => '1'],
                        ['type' => 'authors', 'id' => '2'],
                    ]],
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $authorSelects = $this->selectsFrom($logger, 'author');
        self::assertCount(
            0,
            $authorSelects,
            \sprintf(
                "a many-to-many create must resolve each editor via getReference (no SELECT); the author table was SELECTed %d time(s):\n%s",
                \count($authorSelects),
                \implode("\n", $authorSelects),
            ),
        );
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aManyToManyReplaceResolvesTheNewLinkageWithoutPerIdSelects(): void
    {
        $logger = $this->logger();

        // PATCH …/relationships/editors replaces the join-table membership. Resolving
        // each incoming author is a getReference + join-row insert (no SELECT), so the
        // author SELECT budget is the same for a 1-id and a 2-id replacement.
        $logger->reset();
        $one = $this->handle(self::BASE_URI . '/articles/1/relationships/editors', 'PATCH', [
            'data' => [['type' => 'authors', 'id' => '1']],
        ]);
        self::assertSame(200, $one->getStatusCode(), (string) $one->getContent());
        $selectsForOne = \count($this->selectsFrom($logger, 'author'));

        $logger->reset();
        $two = $this->handle(self::BASE_URI . '/articles/1/relationships/editors', 'PATCH', [
            'data' => [['type' => 'authors', 'id' => '1'], ['type' => 'authors', 'id' => '2']],
        ]);
        self::assertSame(200, $two->getStatusCode(), (string) $two->getContent());
        $selectsForTwo = \count($this->selectsFrom($logger, 'author'));

        self::assertSame(
            $selectsForOne,
            $selectsForTwo,
            \sprintf(
                'a many-to-many replace must resolve incoming ids via getReference, so the author SELECT count is O(1) in the linkage size; 1 id ran %d, 2 ids ran %d',
                $selectsForOne,
                $selectsForTwo,
            ),
        );
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function anInverseOneToManyReplaceResolvesTheNewLinkageWithoutPerIdSelects(): void
    {
        self::markTestSkipped(
            'ACCEPTED limitation, documented not fixed for v1 (bundle ADR 0072 / '
            . 'doctrine.md#relationship-write-query-cost): replacing an inverse '
            . 'one-to-many (FK on the child, e.g. `comments`) sets the owning-side FK on '
            . 'each incoming member via DoctrineDataPersister::attachOwner() '
            . '($member->{owningField} = $owner), which initialises the getReference '
            . 'proxy — one SELECT per incoming id, so the SELECT budget is O(linkage '
            . 'size). Re-pointing managed children through the ORM inherently costs N '
            . 'loads; the bulk-UPDATE alternative would bypass the unit of work and '
            . 'lifecycle events. Many-to-many writes avoid it (proven above). If the '
            . 'path is ever optimised, this test becomes a non-scaling assertion: a '
            . '2-id and a 4-id inverse-one-to-many replace would issue the same number '
            . 'of `comment` SELECTs (resolving incoming ids adds none).',
        );
    }

    /**
     * The SELECT statements (start with `SELECT`) that read the given table.
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

    private function logger(): QueryCountingLogger
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);

        return $logger;
    }
}
