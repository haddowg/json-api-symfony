<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedProviderBatch\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * A run holds no progress, advertisers or notes: their providers answer for it. The
 * in-memory kernel serves the same class.
 */
#[ORM\Entity]
#[ORM\Table(name: 'rpb_run')]
class RunEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
        #[ORM\Column]
        public string $name,
    ) {}
}
