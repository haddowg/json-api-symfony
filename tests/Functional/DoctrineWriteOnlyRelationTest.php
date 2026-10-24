<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\WriteOnlyRelationDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see WriteOnlyRelationConformanceTestCase} against the Doctrine provider, plus a
 * query-log witness that a read never loads the write-only relation.
 */
final class DoctrineWriteOnlyRelationTest extends WriteOnlyRelationConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return WriteOnlyRelationDoctrineTestKernel::class;
    }

    #[Test]
    #[Group('profile:relationship-queries')]
    public function aRelationshipQueriesReadNeverLoadsTheWriteOnlyRelation(): void
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);
        $logger->reset();

        // Under the profile every withData() to-many is windowed. `secretComments` is
        // the only relation over `pinned_article_id`, so any statement filtering on that
        // column is a load of the write-only relation.
        $response = $this->handle('/articles', extraServer: ['HTTP_ACCEPT' => self::PROFILE_ACCEPT]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $loads = \array_values(\array_filter(
            $logger->statements(),
            static fn(string $sql): bool => \str_contains(\strtolower($sql), 'where')
                && \str_contains(\strtolower(\substr($sql, (int) \strpos(\strtolower($sql), 'where'))), 'pinned_article_id'),
        ));
        self::assertSame([], $loads, "a write-only relation must not be loaded on a read:\n" . \implode("\n", $loads));

        // The readable withData() `comments` is still windowed, so the probe would see a load.
        self::assertNotSame([], \array_filter(
            $logger->statements(),
            static fn(string $sql): bool => \str_contains(\strtolower($sql), 'from comment'),
        ));
    }
}
