<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * An owner keyed by a `ulid` id.
 */
#[ORM\Entity]
#[ORM\Table(name: 'im_ulid_owner')]
class ImUlidOwner
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        public Ulid $id,
        #[ORM\Column(type: 'string')]
        public string $name = '',
    ) {}
}
