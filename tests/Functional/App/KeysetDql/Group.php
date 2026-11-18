<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql;

/**
 * A league or division held in memory, with every team.
 */
final class Group
{
    /**
     * @param list<Team> $teams
     */
    public function __construct(
        public int $id,
        public array $teams,
    ) {}
}
