<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * A code keyed by a string, so a text morph column compares with its id as it is.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mk_code')]
class MkCodeEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public string $id,
        #[ORM\Column]
        public string $name,
        #[ORM\Column]
        public bool $hidden = false,
    ) {}
}
