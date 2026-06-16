<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataProvider;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\DataProvider\InMemoryStore;
use haddowg\JsonApiBundle\Tests\Functional\App\Article;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The store-provided (auto-increment) id witness for the in-memory reference
 * adapter: with an `assignId` closure the shared {@see InMemoryStore} mints the
 * next sequential id on an id-less write, mirroring a database `AUTO`/`IDENTITY`
 * column, so the band-aided `generateUsing()` is no longer needed to make the
 * in-memory provider writable. Without `assignId` the store still throws on an
 * id-less write (the read-only seam is unchanged).
 */
final class InMemoryStoreAutoAssignTest extends TestCase
{
    #[Test]
    public function aStoreProvidedCreateGetsTheNextSequentialIdAfterTheSeed(): void
    {
        [$provider, $persister] = $this->writableArticles();

        $created = $persister->create('articles', $persister->instantiate('articles'));
        \assert($created instanceof Article);

        // Two seeded rows (ids 1, 2), so the sequence continues at 3.
        self::assertSame(3, $created->id);
        self::assertSame($created, $provider->fetchOne('articles', '3'));

        $next = $persister->create('articles', $persister->instantiate('articles'));
        \assert($next instanceof Article);
        self::assertSame(4, $next->id);
    }

    #[Test]
    public function anIdLessWriteWithoutAssignIdStillThrows(): void
    {
        // A read-only store (no identify, no assignId) is unchanged: it refuses to
        // key a written item.
        $store = new InMemoryStore(['1' => new Article(1, 'Seeded')]);

        $this->expectException(\LogicException::class);

        $store->save(new Article());
    }

    /**
     * @return array{InMemoryDataProvider, InMemoryDataPersister}
     */
    private function writableArticles(): array
    {
        $provider = new InMemoryDataProvider(
            'articles',
            [
                '1' => new Article(1, 'Alpha'),
                '2' => new Article(2, 'Bravo'),
            ],
            static function (object $item): string {
                \assert($item instanceof Article);

                return $item->id === null ? '' : (string) $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Article);

                $item->id = (int) $id;
            },
        );

        $persister = new InMemoryDataPersister('articles', $provider->store(), static fn(): Article => new Article());

        return [$provider, $persister];
    }
}
