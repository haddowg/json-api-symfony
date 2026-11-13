<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedQuery;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'rq_book')]
class RqBook
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(type: 'string')]
        public string $title = '',
        #[ORM\ManyToOne(targetEntity: RqPerson::class, inversedBy: 'books')]
        public ?RqPerson $writer = null,
    ) {
        $writer?->books->add($this);
    }
}
