<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\CursorShelfFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see LinkageCursorConformanceTestCase} against the Doctrine provider: the
 * same assertions as the in-memory witness, the linkage window executed as the
 * real DQL keyset push-down inside the RelationScope parent scope (bundle ADR
 * 0114).
 *
 * On top of the shared suite, the PIVOT linkage cursor: the `pivotWidgets`
 * relationship endpoint windows the association-entity keyset (`?sort=slot`
 * resolves to the `pivot` alias) and each identifier carries its `meta.pivot`
 * — the pivot map and the boundary tokens ride the same query.
 */
final class DoctrineLinkageCursorTest extends LinkageCursorConformanceTestCase
{
    use SeedsDoctrineCursorShelves;

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function aPivotLinkageCursorPageWindowsIdentifiersWithTheirPivotMeta(): void
    {
        // sort=slot, size 3: the pivot-aliased keyset page is widgets 4, 5, 3
        // (slot asc, id tiebreak) rendered as identifiers, each carrying its
        // association row's meta.pivot.slot — the map and the boundary tokens
        // come off the SAME query.
        $document = $this->fetchDocument('/cursorShelves/1/relationships/pivotWidgets?sort=slot&page[size]=3');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame(['4', '5', '3'], \array_column($data, 'id'));

        $slots = CursorShelfFixtures::slots();
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            self::assertSame('cursorWidgets', $identifier['type'] ?? null);
            $id = $identifier['id'] ?? null;
            self::assertIsString($id);
            $meta = $identifier['meta'] ?? null;
            self::assertIsArray($meta);
            self::assertSame(['slot' => $slots[(int) $id]], $meta['pivot'] ?? null);
        }
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function forwardPagingWalksTheWholePivotLinkageInSlotOrder(): void
    {
        // Follow next through the whole pivot linkage under ?sort=slot: the walk
        // must equal the pivot RELATED endpoint's slot walk verbatim — slot asc
        // with the far PK tiebreak, the boundary crossing the slot=2 tie.
        $expected = ['4', '5', '3', '7', '2', '6', '1', '8'];

        $walked = $this->walkForward('/cursorShelves/1/relationships/pivotWidgets?sort=slot', 3);

        self::assertSame($expected, $walked, 'the pivot linkage walk must page the pivot-aliased keyset');
    }
}
