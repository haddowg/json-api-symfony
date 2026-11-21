<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\StaticAnalysis;

use Doctrine\ORM\Mapping as ORM;

/**
 * The association entity of an album's tracks, which keeps the members an open pivot takes.
 */
#[ORM\Entity]
class HookAlbumTrack
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $extras = null;
}
