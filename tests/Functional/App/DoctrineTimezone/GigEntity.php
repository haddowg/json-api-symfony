<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DoctrineTimezone;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine twin of {@see \haddowg\JsonApiBundle\Tests\Functional\App\Gig}, served
 * as `gigs`, `gig-listings` and `raw-gigs`. Every date column is stored in Paris local
 * time ({@see ParisDateTimeType}), the `Date` and `Time` fields included.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tz_gig')]
class GigEntity
{
    /**
     * @var Collection<int, GigPerformerEntity>
     */
    #[ORM\OneToMany(targetEntity: GigPerformerEntity::class, mappedBy: 'gig')]
    public Collection $gigPerformers;

    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column(type: 'paris_datetime')]
        public ?\DateTimeImmutable $startsAt = null,
        #[ORM\Column(type: 'paris_datetime')]
        public ?\DateTimeImmutable $announcedAt = null,
        #[ORM\Column(type: 'paris_datetime')]
        public ?\DateTimeImmutable $playedOn = null,
        #[ORM\Column(type: 'paris_datetime')]
        public ?\DateTimeImmutable $doorsAt = null,
        #[ORM\ManyToOne(targetEntity: VenueEntity::class)]
        public ?VenueEntity $venue = null,
    ) {
        $this->gigPerformers = new ArrayCollection();
    }
}
