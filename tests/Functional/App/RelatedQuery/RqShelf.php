<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedQuery;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'rq_shelf')]
class RqShelf
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    /**
     * @var Collection<int, RqBook>
     */
    #[ORM\ManyToMany(targetEntity: RqBook::class)]
    #[ORM\JoinTable(name: 'rq_shelf_book')]
    public Collection $books;

    #[ORM\ManyToOne(targetEntity: RqBook::class)]
    public ?RqBook $featured = null;

    public function __construct(
        #[ORM\Column(type: 'string')]
        public string $name = '',
    ) {
        $this->books = new ArrayCollection();
    }
}
