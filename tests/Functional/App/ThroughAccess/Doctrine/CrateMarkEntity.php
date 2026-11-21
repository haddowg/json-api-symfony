<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * One resource a crate is marked with, as a stored type and id.
 */
#[ORM\Entity]
#[ORM\Table(name: 'through_crate_mark')]
class CrateMarkEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\ManyToOne(targetEntity: CrateEntity::class, inversedBy: 'marks')]
        #[ORM\JoinColumn(nullable: false)]
        public CrateEntity $crate,
        #[ORM\Column]
        public string $markType,
        #[ORM\Column]
        public string $markId,
    ) {
        $crate->marks->add($this);
    }
}
