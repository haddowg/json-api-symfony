<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql;

interface ScoredTeam
{
    public function base(): ?int;

    public function bonus(): ?int;

    /**
     * @return list<int>
     */
    public function scores(): array;
}
