<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cm_advertiser')]
class CmAdvertiser
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(type: 'string')]
        public string $name,
        #[ORM\Column(type: 'string')]
        public string $code = '',
        #[ORM\Column(type: 'boolean')]
        public bool $hidden = false,
    ) {}
}
