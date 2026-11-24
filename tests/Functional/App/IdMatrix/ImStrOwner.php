<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use Doctrine\ORM\Mapping as ORM;

/**
 * An owner keyed by a `string` id.
 */
#[ORM\Entity]
#[ORM\Table(name: 'im_str_owner')]
class ImStrOwner
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string')]
        public string $id,
        #[ORM\Column(type: 'string')]
        public string $name = '',
    ) {}
}
