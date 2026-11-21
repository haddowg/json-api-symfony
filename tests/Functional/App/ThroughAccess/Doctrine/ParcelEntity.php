<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'through_parcel')]
class ParcelEntity
{
    /**
     * @var Collection<int, TagEntity>
     */
    #[ORM\ManyToMany(targetEntity: TagEntity::class)]
    #[ORM\JoinTable(name: 'through_parcel_tag')]
    public Collection $tags;

    /**
     * @param list<TagEntity> $tags
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column]
        public string $owner = '',
        #[ORM\Column]
        public string $label = '',
        #[ORM\ManyToOne(targetEntity: CrateEntity::class, inversedBy: 'parcels')]
        #[ORM\JoinColumn(nullable: true)]
        public ?CrateEntity $crate = null,
        array $tags = [],
    ) {
        $this->tags = new ArrayCollection($tags);
        $crate?->parcels->add($this);
    }
}
