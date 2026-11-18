<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql;

final class Team implements ScoredTeam
{
    /**
     * @param list<int> $scores
     */
    public function __construct(
        public ?int $id = null,
        public string $name = '',
        public ?int $base = null,
        public ?int $bonus = null,
        public array $scores = [],
    ) {}

    public function base(): ?int
    {
        return $this->base;
    }

    public function bonus(): ?int
    {
        return $this->bonus;
    }

    public function scores(): array
    {
        return $this->scores;
    }
}
