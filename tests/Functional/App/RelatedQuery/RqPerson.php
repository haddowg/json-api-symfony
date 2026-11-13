<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedQuery;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'rq_person')]
class RqPerson
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    /**
     * @var Collection<int, RqBook>
     */
    #[ORM\OneToMany(targetEntity: RqBook::class, mappedBy: 'writer')]
    public Collection $books;

    public function __construct(
        #[ORM\Column(type: 'string')]
        public string $name = '',
        #[ORM\ManyToOne(targetEntity: RqPerson::class)]
        public ?RqPerson $mentor = null,
    ) {
        $this->books = new ArrayCollection();
    }
}
