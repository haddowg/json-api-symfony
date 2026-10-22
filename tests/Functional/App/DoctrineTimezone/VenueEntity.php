<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DoctrineTimezone;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine twin of {@see \haddowg\JsonApiBundle\Tests\Functional\App\Venue}, its
 * date column stored in Paris local time ({@see ParisDateTimeType}).
 */
#[ORM\Entity]
#[ORM\Table(name: 'tz_venue')]
class VenueEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
        #[ORM\Column(type: 'paris_datetime')]
        public ?\DateTimeImmutable $openedAt = null,
    ) {}
}
