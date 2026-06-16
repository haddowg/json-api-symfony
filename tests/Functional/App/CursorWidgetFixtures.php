<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * The one source of the `cursorWidgets` seed for the cursor (keyset) conformance
 * suite: the in-memory provider and the Doctrine test database seed from the same
 * map, so both providers assert against identical content and a divergence
 * localizes to a provider's keyset execution.
 *
 * The data is deliberately shaped to exercise every keyset branch:
 *  - `category` carries ties (guide × 4, news × 4), so the appended PK tiebreak
 *    is the ONLY thing keeping tied rows totally ordered;
 *  - `priority` is NULLABLE with nulls (ids 3, 6) sitting mid-collection, so an
 *    asc page walks INTO the null bucket at the end and a desc page out of it at
 *    the start — the NULL=largest branch;
 *  - `releasedAt` is a NULLABLE datetime (nulls at ids 4, 6) for the date-keyed,
 *    typed-binding case, with values straddling page boundaries.
 *
 * Ids are per-type sequential ints (1..8) matching the order the Doctrine
 * `AUTO` column assigns on insert.
 */
final class CursorWidgetFixtures
{
    /**
     * Keyed by widget id. PHP coerces the numeric-string keys to int.
     *
     * @return array<int|string, array{category: string, priority: ?int, releasedAt: ?string}>
     */
    public static function data(): array
    {
        return [
            '1' => ['category' => 'guide', 'priority' => 30, 'releasedAt' => '2024-01-05T00:00:00+00:00'],
            '2' => ['category' => 'guide', 'priority' => 10, 'releasedAt' => '2024-03-01T00:00:00+00:00'],
            '3' => ['category' => 'news', 'priority' => null, 'releasedAt' => '2024-02-10T00:00:00+00:00'],
            '4' => ['category' => 'guide', 'priority' => 30, 'releasedAt' => null],
            '5' => ['category' => 'news', 'priority' => 20, 'releasedAt' => '2024-01-20T00:00:00+00:00'],
            '6' => ['category' => 'news', 'priority' => null, 'releasedAt' => null],
            '7' => ['category' => 'guide', 'priority' => 10, 'releasedAt' => '2024-05-01T00:00:00+00:00'],
            '8' => ['category' => 'news', 'priority' => 20, 'releasedAt' => '2024-04-15T00:00:00+00:00'],
        ];
    }
}
