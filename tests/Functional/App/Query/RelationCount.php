<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Query;

/**
 * Shared helper for the relation-count demonstrator arms: counts a related value
 * (the in-memory side) and validates an association name before it is interpolated
 * into DQL (the Doctrine side).
 */
final class RelationCount
{
    /**
     * The cardinality of a related value: a to-many collection's size, `0` for a
     * null/absent to-one, `1` for a present to-one.
     */
    public static function of(mixed $related): int
    {
        if ($related === null) {
            return 0;
        }

        if (\is_array($related)) {
            return \count($related);
        }

        if ($related instanceof \Countable) {
            return \count($related);
        }

        if ($related instanceof \Traversable) {
            return \count(\iterator_to_array($related, false));
        }

        return 1;
    }

    /**
     * Validates `$relation` as a Doctrine association identifier before it is
     * interpolated into a `SIZE(<alias>.<relation>)` DQL fragment — the association
     * name is server-declared, never client-supplied, but a typo should fail loudly.
     */
    public static function assertAssociation(string $relation): string
    {
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $relation) !== 1) {
            throw new \LogicException(\sprintf('"%s" is not a valid Doctrine association name.', $relation));
        }

        return $relation;
    }
}
