<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the in-memory `gizmos` provider for the endpoint-exposure witness, seeded
 * with one gizmo carrying an {@see Author} and two {@see Comment}s. No persister is
 * needed — every P4 endpoint-exposure assertion fires before any write reaches the
 * persister.
 */
final class GizmoFactory
{
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
