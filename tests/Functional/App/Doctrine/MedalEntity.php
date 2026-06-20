<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped far (related) `medals` type of the request-aware-predicates
 * fixture, mirroring the in-memory {@see \haddowg\JsonApiBundle\Tests\Functional\App\Medal}.
 * A store-provided `AUTO` integer id rendered as a string on the wire. The to-many
 * `badges` is the inverse side of {@see BadgeEntity::$medals} so a badge is reachable
 * as the related/included resource off a medal. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'medal')]
class MedalEntity
{
    /**
     * @var Collection<int, BadgeEntity>
     */
    #[ORM\ManyToMany(targetEntity: BadgeEntity::class, mappedBy: 'medals')]
    public Collection $badges;

    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $title = '',
    ) {
        $this->badges = new ArrayCollection();
    }
}
