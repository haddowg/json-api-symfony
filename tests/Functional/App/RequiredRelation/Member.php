<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RequiredRelation;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'rr_member')]
class Member
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'NONE')]
        #[ORM\Column(type: 'integer')]
        public ?int $id = null,
        #[ORM\Column(type: 'string')]
        public string $name = '',
    ) {}
}
