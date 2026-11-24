<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A note about one target and pinned to others, each kept as a stored type and an id in a
 * text column that holds ids of every kind.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mk_note')]
class MkNoteEntity
{
    /**
     * @var Collection<int, MkPinEntity>
     */
    #[ORM\OneToMany(targetEntity: MkPinEntity::class, mappedBy: 'note')]
    public Collection $pins;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
        #[ORM\Column(nullable: true)]
        public ?string $subjectType = null,
        #[ORM\Column(nullable: true)]
        public ?string $subjectId = null,
    ) {
        $this->pins = new ArrayCollection();
    }
}
