<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MappedOrder;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mo_tag')]
class MoTag
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $label = '',
    ) {}
}
