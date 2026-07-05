<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\CursorShelfFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see PivotRelatedCursorConformanceTestCase} against the Doctrine provider —
 * the pivot-aware reference: the cursor fetch runs as ONE DQL statement over
 * the {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CursorShelfWidgetEntity}
 * association entity, so every member renders `meta.pivot.slot` and the `slot`
 * pivot column joins the sort vocabulary (bundle ADR 0114).
 *
 * On top of the shared walks, this side exercises the PIVOT-ALIASED keyset —
 * `?sort=slot` resolves to the `pivot` join alias at SQL-build time, ties on
 * `slot` fall to the far PK tiebreak across a page boundary, and a backward
 * page over the pivot column round-trips — the surface only a pivot-aware
 * provider has.
 */
final class DoctrinePivotRelatedCursorTest extends PivotRelatedCursorConformanceTestCase
{
    use SeedsDoctrineCursorShelves;

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }

    protected function expectsPivotMeta(): bool
    {
        return true;
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:fetching-pagination')]
    public function aPivotSortWalksThePivotAliasedKeysetWithThePkTiebreak(): void
    {
        // sort=slot over shelf 1: slot asc with the far PK tiebreak —
        // 1:(4,5) 2:(3,7) 3:(2,6) 4:(1,8). Page size 3 puts a boundary INSIDE the
        // slot=2 tie (page 1 ends at widget 3), so the next page must resume at
        // widget 7 through the keyset's equality-prefix + PK-tiebreak level over
        // the PIVOT-aliased column.
        $expected = ['4', '5', '3', '7', '2', '6', '1', '8'];

        $walked = $this->walkForward('/cursorShelves/1/pivotWidgets?sort=slot', 3);

        self::assertSame($expected, $walked, 'the pivot-aliased keyset must walk slot order with the PK tiebreak');
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:fetching-pagination')]
    public function aBackwardPivotSortPageEqualsItsForwardPage(): void
    {
        // Follow next to page 2 of the slot walk, then its prev back: the backward
        // page must equal page 1 (the flip+slice+reverse round-trip over the
        // pivot-aliased column).
        [$firstIds, $links] = $this->page('/cursorShelves/1/pivotWidgets?sort=slot&page[size]=3');
        self::assertSame(['4', '5', '3'], $firstIds);

        [, $secondLinks] = $this->page($this->relativePath($this->href($links['next'])));
        [$backIds] = $this->page($this->relativePath($this->href($secondLinks['prev'])));

        self::assertSame($firstIds, $backIds, 'the backward pivot-sort page must equal its forward page');
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:fetching-pagination')]
    public function pivotMetaRidesEveryPageOfAPivotSortWalk(): void
    {
        // The boundary tokens and the pivot map come off the SAME query: a deep
        // page under `?sort=slot` still carries each member's meta.pivot.slot.
        [, $links] = $this->page('/cursorShelves/1/pivotWidgets?sort=slot&page[size]=3');
        $response = $this->handle($this->relativePath($this->href($links['next'])));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame(['7', '2', '6'], \array_column($data, 'id'));

        $slots = CursorShelfFixtures::slots();
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $meta = $resource['meta'] ?? null;
            self::assertIsArray($meta);
            self::assertSame(['slot' => $slots[(int) $id]], $meta['pivot'] ?? null);
        }
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:errors')]
    public function aCursorMintedUnderThePivotSortIsStaleUnderAnotherSort(): void
    {
        // A token minted under sort=slot re-used under sort=priority,id changed
        // the keyset columns — 400 STALE, exactly as a root-column flip is.
        [, $links] = $this->page('/cursorShelves/1/pivotWidgets?sort=slot&page[size]=3');
        $afterToken = $this->cursorParam($this->href($links['next']), 'after');

        $response = $this->handle(\sprintf(
            '/cursorShelves/1/pivotWidgets?sort=priority,id&page[size]=3&page[after]=%s',
            \rawurlencode($afterToken),
        ));

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $error = $this->firstError($this->decode($response));
        self::assertSame('CURSOR_STALE', $error['code'] ?? null);
        self::assertSame(['parameter' => 'page[after]'], $error['source'] ?? null);
    }
}
