<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DoctrineTimezone;

use Doctrine\ORM\Mapping as ORM;

/**
 * A performer booked for a {@see GigEntity} through {@see GigPerformerEntity}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tz_performer')]
class PerformerEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
    ) {}
}
