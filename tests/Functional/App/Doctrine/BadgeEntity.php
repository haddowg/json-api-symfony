<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped `badges` of the request-aware-predicates fixture — the same
 * shape as the in-memory {@see \haddowg\JsonApiBundle\Tests\Functional\App\Badge},
 * persisted to the test SQLite database. A store-provided `AUTO` integer id; the
 * to-many `medals` is the owning side of a bidirectional {@see ORM\ManyToMany}
 * ({@see MedalEntity::$badges} is the inverse) so a relationship mutation resolves
 * linkage ids to managed {@see MedalEntity} references and sets the join rows, and a
 * badge is reachable as the related/included resource off a medal. Not `final` so
 * Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'badge')]
class BadgeEntity
{
    /**
     * @var Collection<int, MedalEntity>
     */
    #[ORM\ManyToMany(targetEntity: MedalEntity::class, inversedBy: 'badges')]
    #[ORM\JoinTable(name: 'badge_medals')]
    public Collection $medals;

    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
        #[ORM\Column(nullable: true)]
        public ?string $secret = null,
        #[ORM\Column(nullable: true)]
        public ?string $writeOnlySecret = null,
        #[ORM\Column(nullable: true)]
        public ?string $rank = null,
        #[ORM\Column(nullable: true)]
        public ?string $clearance = null,
    ) {
        $this->medals = new ArrayCollection();
    }
}
