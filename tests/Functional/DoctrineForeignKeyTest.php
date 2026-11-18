<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\FkSecret;
use haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\ForeignKeyDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\ForeignKeySeed;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see ForeignKeyConformanceTestCase} on Doctrine, where each read through a foreign key
 * is one bounded query for the whole page of parents, under the related type's query
 * extensions.
 */
final class DoctrineForeignKeyTest extends ForeignKeyConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ForeignKeyDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        parent::afterBoot();

        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (ForeignKeySeed::build() as $rows) {
            foreach ($rows as $row) {
                $entityManager->persist($row);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function aLazyRelationRendersNoLinkageUntilIncluded(): void
    {
        $document = $this->fetch('/fk-users/ada');

        self::assertArrayNotHasKey('data', $this->relationship($document['data'] ?? null, 'items'));
        self::assertArrayNotHasKey('data', $this->relationship($document['data'] ?? null, 'profile'));
    }

    #[Test]
    public function clearingAForeignKeyStorageRequiresIsRefused(): void
    {
        self::assertSame(403, $this->handle('/fk-users/ada/relationships/notes', 'PATCH', ['data' => []])->getStatusCode());
        self::assertSame(403, $this->handle('/fk-users/ada/relationships/notes', 'DELETE', ['data' => [['type' => 'fk-notes', 'id' => '1']]])->getStatusCode());
        self::assertSame(['1'], $this->ids($this->fetch('/fk-users/ada/notes')['data'] ?? null));

        // Joining a row to the parent clears nothing, so it is allowed.
        self::assertSame(200, $this->handle('/fk-users/ada/relationships/notes', 'POST', ['data' => [['type' => 'fk-notes', 'id' => '3']]])->getStatusCode());
        self::assertSame(['1', '3'], $this->ids($this->fetch('/fk-users/ada/notes')['data'] ?? null));
    }

    #[Test]
    public function aReplaceLeavesTheRowsTheScopeHides(): void
    {
        self::assertSame(200, $this->handle('/fk-users/ada/relationships/secrets', 'PATCH', ['data' => []])->getStatusCode());
        self::assertSame([], $this->ids($this->fetch('/fk-users/ada/secrets')['data'] ?? null));

        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->clear();
        $hidden = $entityManager->find(FkSecret::class, 2);
        self::assertInstanceOf(FkSecret::class, $hidden);
        self::assertSame('ada', $hidden->owner);
    }

    #[Test]
    public function aScopedRelatedTypeServesOnlyTheRowsItsExtensionAdmits(): void
    {
        self::assertSame(['1'], $this->ids($this->fetch('/fk-users/ada/secrets')['data'] ?? null));
        self::assertSame(['1'], $this->ids($this->fetch('/fk-users/ada/relationships/secrets')['data'] ?? null));

        $document = $this->fetch('/fk-users?include=secrets&withCount=secrets', 'application/vnd.api+json;profile="' . CountableProfile::URI . '"');
        $users = $document['data'] ?? null;
        self::assertIsArray($users);
        $ada = $this->relationship($users[0] ?? null, 'secrets');
        self::assertSame(['1'], $this->ids($ada['data'] ?? null));
        self::assertSame(['total' => 1], $ada['meta'] ?? null);
        self::assertSame(['fk-secrets:1'], $this->includedKeys($document));
    }

    #[Test]
    public function aCollectionIncludeIsOneBoundedQueryPerRelation(): void
    {
        $logger = $this->logger();

        $this->fetch('/fk-users?include=items,cursorItems');

        // The provider loads Ada's pinned and favourite items by id; the rest read through the foreign key.
        $items = \array_values(\array_filter($this->selectsFrom($logger, 'fk_item'), static fn(string $sql): bool => !\str_contains($sql, '.id = ?')));
        self::assertCount(2, $items, \implode("\n", $items));
        self::assertCount(2, \array_filter($items, static fn(string $sql): bool => \str_contains($sql, 'ROW_NUMBER() OVER')));
    }

    #[Test]
    public function withCountIsOneGroupedQuery(): void
    {
        $logger = $this->logger();

        $this->fetch('/fk-users?withCount=items', 'application/vnd.api+json;profile="' . CountableProfile::URI . '"');

        $counts = \array_values(\array_filter($this->selectsFrom($logger, 'fk_item'), static fn(string $sql): bool => \str_contains(\strtolower($sql), 'count(')));
        self::assertCount(1, $counts, \implode("\n", $counts));
        self::assertStringContainsString('GROUP BY', $counts[0]);
    }

    private function logger(): QueryCountingLogger
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);
        $logger->reset();

        return $logger;
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
