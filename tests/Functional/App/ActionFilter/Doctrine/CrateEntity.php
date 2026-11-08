<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionFilter\Doctrine;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'filter_crate')]
class CrateEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
        #[ORM\Column]
        public string $status = 'open',
        #[ORM\Column]
        public int $weight = 0,
        #[ORM\Column]
        public string $lifecycle = 'live',
        #[ORM\Column]
        public bool $hidden = false,
    ) {}
}
