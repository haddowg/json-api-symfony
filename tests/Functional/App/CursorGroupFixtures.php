<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * The one source of the `cursorGroups` seed for the inverse-FK cursor (keyset) INCLUDE
 * conformance suite: group → member widget ids over the shared {@see CursorWidgetFixtures}
 * rows, so the in-memory provider and the Doctrine test database seed the same memberships
 * and a divergence localizes to a provider's keyset execution.
 *
 * Because `cursorGroups → widgets` is a Doctrine `OneToMany` (each widget carries ONE owning
 * `group_id`), the groups PARTITION the widgets — no widget is a member of two groups. Group
 * 1 holds SIX widgets (incl. the null-priority id 3), group 2 the remaining TWO (id 8
 * priority-20 + the null-priority id 6). So a cursor-resolved COLLECTION include windowing
 * every group in ONE inverse-FK query proves MIXED surplus across the partitions of the same
 * window under a NULLABLE `priority` sort: group 1 over-fills page 1 (a `next`), group 2 fits
 * it exactly with its null member landing LAST (NULL=largest → NO `next`).
 *
 * The membership deliberately mirrors the Laravel adapter's `cursorGroups` fixture so the two
 * inverse-FK cursor-include proofs read against the same shape.
 */
final class CursorGroupFixtures
{
    /**
     * Keyed by group id. PHP coerces the numeric-string keys to int.
     *
     * @return array<int|string, list<int>>
     */
    public static function data(): array
    {
        return [
            '1' => [1, 2, 3, 4, 5, 7],
            '2' => [6, 8],
        ];
    }
}
