<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MappedOrder;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A shelf whose two to-many associations each map an `orderBy` that differs from
 * insertion order: an inverse one-to-many and an owning many-to-many.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mo_shelf')]
class MoShelf
{
    /**
     * @var Collection<int, MoBook>
     */
    #[ORM\OneToMany(targetEntity: MoBook::class, mappedBy: 'shelf')]
    #[ORM\OrderBy(['title' => 'DESC'])]
    public Collection $books;

    /**
     * @var Collection<int, MoTag>
     */
    #[ORM\ManyToMany(targetEntity: MoTag::class)]
    #[ORM\JoinTable(name: 'mo_shelf_tag')]
    #[ORM\OrderBy(['label' => 'DESC'])]
    public Collection $tags;

    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
    ) {
        $this->books = new ArrayCollection();
        $this->tags = new ArrayCollection();
    }
}
