<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FirstPage;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'fp_label')]
class FpLabel
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public ?int $id = null,
        #[ORM\Column(type: 'string')]
        public string $name = '',
    ) {}
}
