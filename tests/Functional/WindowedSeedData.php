<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

/**
 * The single source of the windowed-include-batch conformance seed (bundle ADR 0065):
 * the Doctrine seeding trait ({@see SeedsLargeWindowedRelations}) and the in-memory
 * witness kernel seed from the SAME body lists, so both providers' documents are
 * asserted identical.
 */
final class WindowedSeedData
{
    /**
     * Article 1's 50 comment bodies in PK (insertion) order: `comment-00` .. `comment-49`.
     * Sorting DESC by body selects `comment-49` .. `comment-45` onto a size-5 page-1
     * window (the last 5), so the bounded-fetch and true-total (50) assertions hold.
     *
     * @return list<string>
     */
    public static function largeBodies(): array
    {
        $bodies = [];
        for ($i = 0; $i < 50; $i++) {
            $bodies[] = \sprintf('comment-%02d', $i);
        }

        return $bodies;
    }

    /**
     * Article 2's 3 comment bodies.
     *
     * @return list<string>
     */
    public static function smallBodies(): array
    {
        return ['alpha', 'beta', 'gamma'];
    }

    /**
     * Article 3's two comments, both with the SAME body `tie` — so they are tied on the
     * sort column and resolve only by the PK tiebreak (insertion order == PK order), the
     * tie-determinism assertion.
     *
     * @return list<string>
     */
    public static function tieBodies(): array
    {
        return ['tie', 'tie'];
    }
}
