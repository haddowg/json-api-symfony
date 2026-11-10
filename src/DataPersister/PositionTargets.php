<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\Positions;

/**
 * The positions a positioned relation's members take after a write, from core's
 * {@see Positions}: a replace takes each member's linkage index, an add appends the new
 * members after the highest position, and a remove leaves the others where they are.
 * Members are keyed by whatever the caller keys its rows by.
 *
 * @internal
 */
final class PositionTargets
{
    /**
     * @param array<string, int> $current  the positions before the write
     * @param list<string>       $incoming the linkage's members, in order
     *
     * @return array<string, int>
     */
    public static function for(Mode $mode, array $current, array $incoming): array
    {
        return match ($mode) {
            Mode::Replace => Positions::forReplace($incoming),
            Mode::Add => $current + Positions::forAdd($current, $incoming),
            Mode::Remove => \array_diff_key($current, \array_flip($incoming)),
        };
    }

    /**
     * Each row's stored position, leaving out a row that holds none.
     *
     * @param array<string, object> $rows
     *
     * @return array<string, int>
     */
    public static function current(array $rows, string $column): array
    {
        $current = [];
        foreach ($rows as $key => $row) {
            $position = Accessor::get($row, $column);
            if (\is_int($position) || (\is_string($position) && \ctype_digit($position))) {
                $current[(string) $key] = (int) $position;
            }
        }

        return $current;
    }

    /**
     * Writes the final positions onto the rows, for a store with no unique position
     * key to work around.
     *
     * @param array<string, int>    $target
     * @param array<string, object> $rows
     */
    public static function writeFinal(array $target, array $rows, string $column): void
    {
        foreach ($target as $key => $position) {
            if (isset($rows[$key])) {
                Accessor::set($rows[$key], $column, $position);
            }
        }
    }
}
