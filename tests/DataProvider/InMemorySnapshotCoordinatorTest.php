<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataProvider;

use haddowg\JsonApiBundle\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiBundle\DataProvider\InMemoryStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The cross-store snapshot coordinator (bundle ADR 0089). Slice D closes the
 * asymmetry with the {@see \haddowg\JsonApiBundle\DataPersister\WriteTransactionContext}:
 * the coordinator implements {@see ResetInterface} (auto-tagged `kernel.reset` when
 * registered as a service), so a batch that left a session open undrained — an
 * exception between {@see InMemorySnapshotCoordinator::open()} and
 * {@see InMemorySnapshotCoordinator::restore()}/{@see InMemorySnapshotCoordinator::commit()}
 * — cannot carry its captured pre-image into the next batch in a long-lived worker.
 */
final class InMemorySnapshotCoordinatorTest extends TestCase
{
    #[Test]
    public function itIsAResettableService(): void
    {
        self::assertInstanceOf(ResetInterface::class, new InMemorySnapshotCoordinator());
    }

    #[Test]
    public function resetClosesAnOpenSessionWithoutRestoring(): void
    {
        $coordinator = new InMemorySnapshotCoordinator();

        // A store wired to the coordinator; opening a session captures its pre-image.
        new InMemoryStore([], coordinator: $coordinator);
        $coordinator->open();
        self::assertTrue($coordinator->isOpen());

        // The kernel.reset hook between requests in a long-lived container: the leaked
        // open session is discarded, so it cannot poison the next batch.
        $coordinator->reset();

        self::assertFalse($coordinator->isOpen());
    }

    #[Test]
    public function afterResetTheNextSessionCapturesTheCurrentStateNotTheStaleOne(): void
    {
        $coordinator = new InMemorySnapshotCoordinator();
        $identify = static fn(object $item): string => $item instanceof CoordinatorTestItem ? $item->id : '';
        $store = new InMemoryStore([], $identify, coordinator: $coordinator);

        // Batch 1 opens a session (capturing the empty store) but never restores/commits
        // — it leaks. A reset discards the captured empty pre-image.
        $coordinator->open();
        $store->save(new CoordinatorTestItem('1'));
        $coordinator->reset();

        // Batch 2 opens a fresh session — capturing the CURRENT (one-item) state — then
        // adds another item and rolls back. Restore must return to the one-item state
        // captured this session, NOT the stale empty pre-image batch 1 leaked.
        $coordinator->open();
        $store->save(new CoordinatorTestItem('2'));
        $coordinator->restore();

        self::assertNotNull($store->find('1'));
        self::assertNull($store->find('2'));
    }
}

/**
 * A trivial named (serializable) item the coordinator can snapshot — an anonymous
 * class cannot be serialized.
 */
final class CoordinatorTestItem
{
    public function __construct(public string $id) {}
}
