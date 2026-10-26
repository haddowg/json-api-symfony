<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;

/**
 * A shelf keyed by a Doctrine `uuid` id, which sqlite stores as a 16-byte blob.
 */
#[ORM\Entity]
#[ORM\Table(name: 'uuid_shelf')]
class UuidShelfEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    public ?Uuid $id = null;

    #[ORM\Column]
    public string $name = '';

    /**
     * @var Collection<int, UuidBookEntity>
     */
    #[ORM\OneToMany(targetEntity: UuidBookEntity::class, mappedBy: 'shelf')]
    public Collection $books;

    public function __construct()
    {
        $this->books = new ArrayCollection();
    }
}
