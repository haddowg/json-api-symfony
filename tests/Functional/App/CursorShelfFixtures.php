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
        ];
    }
}
