<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotIdForms;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A player keyed by a UUID string, compared in any case as a case-insensitive
 * collation compares it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pif_player')]
class PifPlayer
{
    /**
     * @var Collection<int, PifSeat>
     */
    #[ORM\OneToMany(targetEntity: PifSeat::class, mappedBy: 'player')]
    public Collection $seats;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 36, options: ['collation' => 'NOCASE'])]
        public string $id,
        #[ORM\Column]
        public string $name = '',
    ) {
        $this->seats = new ArrayCollection();
    }
}
