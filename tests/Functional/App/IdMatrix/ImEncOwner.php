<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An owner keyed by a `uuid` id.
 */
#[ORM\Entity]
#[ORM\Table(name: 'im_enc_owner')]
class ImEncOwner
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        public Uuid $id,
        #[ORM\Column(type: 'string')]
        public string $name = '',
    ) {}
}
