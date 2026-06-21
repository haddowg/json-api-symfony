<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataProvider;

use haddowg\JsonApiBundle\DataProvider\InMemoryStore;
use haddowg\JsonApiBundle\Tests\Functional\App\Article;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The in-memory analogue of a database transaction's commit/rollback: the store's
 * {@see InMemoryStore::snapshot()}/{@see InMemoryStore::restore()} the
 * {@see \haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister} delegates its
 * begin/rollback to. A restore must recover the EXACT pre-snapshot state — every
 * stored object back to its pre-mutation field values (so the snapshot must
 * DEEP-CLONE, since the persister's update() mutates the same object reference in
 * place) AND the id counter (so an id minted in the discarded batch is rewound).
 */
final class InMemoryStoreSnapshotTest extends TestCase
{
    #[Test]
    public function restoreRecoversTheExactStateIncludingInPlaceUpdatesAndTheIdCounter(): void
    {
        $alpha = new Article(1, 'Alpha');
        $bravo = new Article(2, 'Bravo');

        $store = new InMemoryStore(
            ['1' => $alpha, '2' => $bravo],
            static function (object $item): string {
                \assert($item instanceof Article);

                return $item->id === null ? '' : (string) $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Article);

                $item->id = (int) $id;
            },
        );

        // Open the "transaction".
        $store->snapshot();

        // (1) An in-place UPDATE: mutate the SAME stored object reference and
        // re-save it (exactly what InMemoryDataPersister::update() does). A shallow
        // array copy would still point at this now-mutated object — only a
        // deep-clone snapshot can roll this back.
        $alpha->title = 'Alpha EDITED';
        $store->save($alpha);

        // (2) A store-provided CREATE: an id-less write mints id 3 (the seed's two
        // numeric ids put the sequence at 3).
        $charlie = new Article(null, 'Charlie');
        $store->save($charlie);
        self::assertSame(3, $charlie->id);

        // (3) A REMOVE.
        $store->remove($bravo);

        // Mid-transaction the store reflects every change.
        self::assertCount(2, $store->all());
        $editedAlpha = $store->find('1');
        \assert($editedAlpha instanceof Article);
        self::assertSame('Alpha EDITED', $editedAlpha->title);
        self::assertNotNull($store->find('3'));
        self::assertNull($store->find('2'));

        // Roll back.
        $store->restore();

        // The items map is back to the original two rows.
        self::assertCount(2, $store->all());
        self::assertNull($store->find('3'));
        self::assertNotNull($store->find('2'));

        // The in-place update is undone — the snapshot's deep clone carried the
        // pre-update title, so the restored 'Alpha' is the original value (NOT the
        // mutated reference).
        $restoredAlpha = $store->find('1');
        \assert($restoredAlpha instanceof Article);
        self::assertSame('Alpha', $restoredAlpha->title);

        $restoredBravo = $store->find('2');
        \assert($restoredBravo instanceof Article);
        self::assertSame('Bravo', $restoredBravo->title);

        // The id counter is rewound too: the next store-provided create gets id 3
        // again, not 4 — the discarded mint did not advance the live sequence.
        $next = new Article(null, 'Charlie II');
        $store->save($next);
        self::assertSame(3, $next->id);
    }

    #[Test]
    public function discardSnapshotKeepsTheWritesMadeSinceSnapshot(): void
    {
        $alpha = new Article(1, 'Alpha');
        $store = new InMemoryStore(
            ['1' => $alpha],
            static function (object $item): string {
                \assert($item instanceof Article);

                return $item->id === null ? '' : (string) $item->id;
            },
        );

        $store->snapshot();

        $alpha->title = 'Alpha EDITED';
        $store->save($alpha);

        // Commit: the snapshot is discarded, so a later restore is a no-op and the
        // edit stands.
        $store->discardSnapshot();
        $store->restore();

        $kept = $store->find('1');
        \assert($kept instanceof Article);
        self::assertSame('Alpha EDITED', $kept->title);
    }

    #[Test]
    public function restoreWithoutASnapshotIsANoOp(): void
    {
        $store = new InMemoryStore(
            ['1' => new Article(1, 'Alpha')],
            static function (object $item): string {
                \assert($item instanceof Article);

                return (string) $item->id;
            },
        );

        // No snapshot held — restore must not blow up or clear the store.
        $store->restore();

        self::assertCount(1, $store->all());
    }
}
