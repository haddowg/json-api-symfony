<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\FirstPage\FirstPageDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\FirstPage\FirstPageSeed;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see FirstPageConformanceTestCase} on Doctrine, where every relation's first pages
 * load in one bounded query for the whole page of parents.
 */
final class DoctrineFirstPageTest extends FirstPageConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return FirstPageDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        parent::afterBoot();

        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (FirstPageSeed::build() as $rows) {
            foreach ($rows as $row) {
                $entityManager->persist($row);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function aCollectionIncludeReadsEachRelationsFirstPagesInOneBoundedQuery(): void
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);
        $logger->reset();

        $this->fetch('/fp-boards?include=cards.comments,labels');

        // `cards` and `featured` read the same association with the same page, so they
        // share one bounded read. `pocketCards` exposes no endpoint, so its linkage is the
        // whole set, read for every board at once.
        $cards = $this->selectsFrom($logger, 'fp_card');
        self::assertCount(1, $this->windowed($cards), \implode("\n", $cards));
        self::assertCount(2, $cards, \implode("\n", $cards));

        $comments = $this->selectsFrom($logger, 'fp_comment');
        self::assertCount(1, $comments, \implode("\n", $comments));
        self::assertCount(1, $this->windowed($comments));

        // The many-to-many windows its join-table pairs, then loads the page's labels by id.
        self::assertCount(1, $this->windowed(\array_values(\array_filter($logger->statements(), static fn(string $sql): bool => \str_contains($sql, 'fp_board_label')))));
    }

    #[Test]
    public function aSingletonsCursorPageIsOneBoundedQuery(): void
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);
        $logger->reset();

        $this->fetch('/desk?include=cards');

        $cards = $this->selectsFrom($logger, 'fp_card');
        self::assertCount(1, $cards, \implode("\n", $cards));
        self::assertCount(1, $this->windowed($cards));
    }

    /**
     * @param list<string> $statements
     *
     * @return list<string>
     */
    private function windowed(array $statements): array
    {
        return \array_values(\array_filter($statements, static fn(string $sql): bool => \str_contains($sql, 'ROW_NUMBER() OVER')));
    }

    /**
     * @return list<string>
     */
    private function selectsFrom(QueryCountingLogger $logger, string $table): array
    {
        return \array_values(\array_filter(
            $logger->statements(),
            static fn(string $sql): bool => \str_starts_with(\ltrim(\strtolower($sql)), 'select') && \str_contains(\strtolower($sql), 'from ' . $table),
        ));
    }
}
