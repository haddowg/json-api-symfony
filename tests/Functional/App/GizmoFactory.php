<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the in-memory `gizmos` provider for the endpoint-exposure witness, seeded
 * with one gizmo carrying an {@see Author} and two {@see Comment}s. A persister is
 * wired so the full-CRUD resource is servable; every P4 endpoint-exposure assertion
 * still fires before any write reaches it.
 */
final class GizmoFactory
{
    public static function createPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('gizmos', $provider->store(), static fn(): Gizmo => new Gizmo());
    }

    public static function createProvider(): InMemoryDataProvider
    {
        $gizmos = [
            'g1' => new Gizmo(
                'g1',
                'Widget',
                new Author(1, 'Ada'),
                [new Comment(1, 'First'), new Comment(2, 'Nice')],
            ),
        ];

        return new InMemoryDataProvider('gizmos', $gizmos, static function (object $item): string {
            \assert($item instanceof Gizmo);

            return $item->id;
        });
    }
}
