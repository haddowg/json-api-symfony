<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid;

use Doctrine\ORM\Mapping as ORM;

/**
 * The association entity behind a book's `credits`, whose foreign keys to the book and
 * the label hold their 16-byte keys, carrying a stored `weight`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'uuid_credit')]
class UuidCreditEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: UuidBookEntity::class, inversedBy: 'credits')]
        #[ORM\JoinColumn(name: 'book_id', referencedColumnName: 'id', nullable: false)]
        public UuidBookEntity $book,
        #[ORM\ManyToOne(targetEntity: UuidLabelEntity::class)]
        #[ORM\JoinColumn(name: 'label_id', referencedColumnName: 'id', nullable: false)]
        public UuidLabelEntity $label,
        #[ORM\Column(type: 'integer')]
        public int $weight = 0,
    ) {}
}
