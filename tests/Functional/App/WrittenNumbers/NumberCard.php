<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WrittenNumbers;

/**
 * Holds each written number as hydration left it, untyped so a float or an int shows.
 */
final class NumberCard
{
    public ?string $id = null;

    public mixed $picked = null;

    public mixed $avoided = null;

    public mixed $ranked = null;

    public mixed $weight = null;

    public mixed $avoidedWeight = null;

    public mixed $whole = null;

    public mixed $counts = null;

    public mixed $weights = null;

    public mixed $stats = null;

    public mixed $hooked = null;

    public ?string $hookedType = null;
}
