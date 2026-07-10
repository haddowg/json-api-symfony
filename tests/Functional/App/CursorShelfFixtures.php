<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * The one source of the `cursorShelves` seed for the related-collection cursor
 * (keyset) conformance suite: shelf → member widget ids over the shared
 * {@see CursorWidgetFixtures} rows, so the in-memory provider and the Doctrine
 * test database seed the same memberships and a divergence localizes to a
 * provider's keyset execution.
 *
 * Shelf 1 holds EVERY widget, so its related pages must equal the primary
 * `/cursorWidgets` keyset pages verbatim; shelf 2 holds only the `news` rows
 * (incl. both null-priority rows 3 and 6), so a walk over it proves the keyset
 * runs INSIDE the parent scope — a leak of a non-member widget is immediately
 * visible, and the null bucket is still exercised on the scoped subset.
 *
 * Shelf 3 holds exactly TWO members (8 priority-20, 6 priority-null), so a
 * cursor-resolved COLLECTION include windowing every shelf in ONE query proves
 * MIXED surplus across the partitions of the same window: shelf 1 over-fills
 * page 1 (a `next`), shelf 3 fits it exactly (NO `next`), and shelf 3's null-
 * priority member lands ON its page — exercising the NULL=largest branch INSIDE
 * `ROW_NUMBER()` for a no-surplus partition alongside shelf 1's null bucket sorted
 * off the page.
 */
final class CursorShelfFixtures
{
    /**
     * Keyed by shelf id. PHP coerces the numeric-string keys to int.
     *
     * @return array<int|string, list<int>>
     */
    public static function data(): array
    {
        return [
            '1' => [1, 2, 3, 4, 5, 6, 7, 8],
            '2' => [3, 5, 6, 8],
            '3' => [6, 8],
        ];
    }

    /**
     * The per-membership `slot` PIVOT value the Doctrine association entity
     * ({@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CursorShelfWidgetEntity})
     * carries for the pivot-related cursor suite (bundle ADR 0114) — `widgetId =>
     * slot`, the same value on every shelf the widget sits on. Slots deliberately
     * TIE in pairs (1:{4,5}, 2:{3,7}, 3:{2,6}, 4:{1,8}), so a `?sort=slot` keyset
     * order is `slot asc, id asc` = 4,5,3,7,2,6,1,8 — a walk that differs from
     * BOTH the id order and every widget-attribute order (it cannot pass by
     * accident), and whose ties force the far PK tiebreak mid-walk.
     *
     * The in-memory provider is not pivot-aware, so it never reads these — the
     * documented boundary the in-memory half of the suite asserts (`?sort=slot`
     * is a 400 there).
     *
     * @return array<int, int>
     */
    public static function slots(): array
    {
        return [
            1 => 4,
            2 => 3,
            3 => 2,
            4 => 1,
            5 => 1,
            6 => 3,
            7 => 2,
            8 => 4,
        ];
    }
}
