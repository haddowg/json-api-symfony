<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeHydration;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A shelf whose `books` are a unidirectional many-to-many, so `?include=books` over a
 * page of shelves takes the pair-shape include batch.
 */
#[ORM\Entity]
#[ORM\Table(name: 'hydration_shelf')]
class HydrationShelfEntity
{
    /** @var Collection<int, HydrationBookEntity> */
    #[ORM\ManyToMany(targetEntity: HydrationBookEntity::class)]
    #[ORM\JoinTable(name: 'hydration_shelf_book')]
    public Collection $books;

    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
    ) {
        $this->books = new ArrayCollection();
    }
}
