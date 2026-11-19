<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotNumbers;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'pn_card')]
class PnCard
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'NONE')]
        #[ORM\Column(type: 'string')]
        public ?string $id = null,
        #[ORM\Column(type: 'string')]
        public string $title = '',
    ) {}
}
