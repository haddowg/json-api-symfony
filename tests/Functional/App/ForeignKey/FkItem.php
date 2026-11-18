<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'fk_item')]
class FkItem
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public ?int $id = null,
        #[ORM\Column(type: 'string')]
        public string $title = '',
        #[ORM\Column(name: 'owner_key', type: 'string', nullable: true)]
        public ?string $ownerKey = null,
    ) {}
}
