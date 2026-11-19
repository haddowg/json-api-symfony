<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExtensionAlias;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ea_book')]
class EaBookEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
        #[ORM\Column]
        public string $title,
        #[ORM\ManyToOne(targetEntity: EaShelfEntity::class, inversedBy: 'books')]
        public ?EaShelfEntity $shelf = null,
    ) {}
}
