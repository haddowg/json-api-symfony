<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A track played on air. A play keeps the id of the radio station that played it in
 * `station`, a plain column: stations are served by
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Provider\StationProvider}, not
 * Doctrine, so there is no association to map. A station reaches its plays through
 * that column as its foreign key.
 *
 * Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'play')]
class Play
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 32)]
        public string $station = '',
        #[ORM\Column(name: 'played_at', type: 'datetime_immutable')]
        public \DateTimeImmutable $playedAt = new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        #[ORM\ManyToOne(targetEntity: Track::class)]
        #[ORM\JoinColumn(nullable: true)]
        public ?Track $track = null,
    ) {}
}
