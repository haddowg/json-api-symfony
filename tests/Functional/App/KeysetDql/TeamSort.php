<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql;

use haddowg\JsonApi\Resource\Sort\KeysetSortInterface;

/**
 * Orders teams by their best member's score, their tier or their points. On Doctrine
 * these are a subquery, a `CASE` and a `COALESCE`.
 */
final readonly class TeamSort implements KeysetSortInterface
{
    public function __construct(public string $key) {}

    public function key(): string
    {
        return $this->key;
    }

    public function keysetValue(mixed $row): ?int
    {
        \assert($row instanceof ScoredTeam);

        return match ($this->key) {
            'topScore' => $row->scores() === [] ? null : \max($row->scores()),
            'tier' => $row->base() === null ? null : ($row->base() >= 10 ? 2 : 1),
            default => $row->bonus() ?? $row->base(),
        };
    }
}
