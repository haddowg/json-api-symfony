<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExtensionAlias;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ea_shelf')]
class EaShelfEntity
{
    /**
     * @var Collection<int, EaBookEntity>
     */
    #[ORM\OneToMany(targetEntity: EaBookEntity::class, mappedBy: 'shelf')]
    public Collection $books;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
        #[ORM\Column]
        public string $name,
    ) {
        $this->books = new ArrayCollection();
    }
}
