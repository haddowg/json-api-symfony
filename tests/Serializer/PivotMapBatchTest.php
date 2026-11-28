<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Serializer;

use haddowg\JsonApiBundle\Serializer\PivotMapBatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A parent whose page was read with its pivot values renders them, and only the parents
 * without one are read, all in one call.
 */
#[CoversClass(PivotMapBatch::class)]
final class PivotMapBatchTest extends TestCase
{
    #[Test]
    public function aLoadedParentRendersItsPageAndTheOthersAreReadTogether(): void
    {
        $loaded = new \stdClass();
        $first = new \stdClass();
        $second = new \stdClass();
        $reads = [];
        $batch = new PivotMapBatch(
            static function (array $parents) use (&$reads, $loaded): array {
                $reads[] = \array_map(\spl_object_id(...), $parents);

                return \in_array($loaded, $parents, true)
                    ? ['p1' => ['A1B2C3D4-E5F6-4A7B-8C9D-0E1F2A3B4C99' => ['shirt' => 9]]]
                    : ['p2' => ['t1' => ['shirt' => 2]], 'p3' => ['t1' => ['shirt' => 3]]];
            },
            [$loaded, $first, $second],
            'App\\Provider',
            loaded: [\spl_object_id($loaded) => ['A1B2C3D4-E5F6-4A7B-8C9D-0E1F2A3B4C01' => ['shirt' => 1]]],
        );

        self::assertSame(['shirt' => 1], $batch->rowFor('p1', $loaded, 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c01'));
        self::assertSame([], $reads);

        self::assertSame(['shirt' => 2], $batch->rowFor('p2', $first, 't1'));
        self::assertSame(['shirt' => 3], $batch->rowFor('p3', $second, 't1'));
        self::assertSame([[\spl_object_id($first), \spl_object_id($second)]], $reads);

        // A member its parent's page did not hold is read with the loaded parents.
        self::assertSame(['shirt' => 9], $batch->rowFor('p1', $loaded, 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c99'));
        self::assertSame([[\spl_object_id($first), \spl_object_id($second)], [\spl_object_id($loaded)]], $reads);
        self::assertNull($batch->rowFor('p1', $loaded, 'missing'));
        self::assertCount(2, $reads);
    }
}
