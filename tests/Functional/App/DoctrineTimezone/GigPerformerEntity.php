<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DoctrineTimezone;

use Doctrine\ORM\Mapping as ORM;

/**
 * The gig/performer association entity, whose Paris-time pivot columns
 * ({@see ParisDateTimeType}) render as `meta.pivot`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tz_gig_performer')]
class GigPerformerEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\ManyToOne(targetEntity: GigEntity::class, inversedBy: 'gigPerformers')]
        public ?GigEntity $gig = null,
        #[ORM\ManyToOne(targetEntity: PerformerEntity::class)]
        public ?PerformerEntity $performer = null,
        #[ORM\Column(type: 'paris_datetime')]
        public ?\DateTimeImmutable $bookedAt = null,
        #[ORM\Column(type: 'paris_datetime')]
        public ?\DateTimeImmutable $confirmedAt = null,
        #[ORM\Column(type: 'paris_datetime')]
        public ?\DateTimeImmutable $contractOn = null,
    ) {}
}
