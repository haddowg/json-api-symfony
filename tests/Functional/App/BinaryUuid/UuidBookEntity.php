<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'uuid_book')]
class UuidBookEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    public ?Uuid $id = null;

    #[ORM\Column]
    public string $title = '';

    #[ORM\ManyToOne(targetEntity: UuidShelfEntity::class, inversedBy: 'books')]
    #[ORM\JoinColumn(name: 'shelf_id', referencedColumnName: 'id', nullable: true)]
    public ?UuidShelfEntity $shelf = null;

    /**
     * @var Collection<int, UuidLabelEntity>
     */
    #[ORM\ManyToMany(targetEntity: UuidLabelEntity::class)]
    #[ORM\JoinTable(name: 'uuid_book_labels')]
    public Collection $labels;

    public function __construct()
    {
        $this->labels = new ArrayCollection();
    }
}
