<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'fk_secret')]
class FkSecret
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public ?int $id = null,
        #[ORM\Column(type: 'string')]
        public string $label = '',
        #[ORM\Column(type: 'string')]
        public string $owner = '',
        #[ORM\Column(type: 'boolean')]
        public bool $hidden = false,
    ) {}
}
