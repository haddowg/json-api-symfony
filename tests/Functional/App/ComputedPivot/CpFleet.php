<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A fleet has many ships and a flagship, so a crew's pivot relations are reached two
 * includes deep, and one crew can be reached by two include paths in one document. Its
 * vessels are ships and crews, named by stored pairs.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cp_fleet')]
class CpFleet
{
    /**
     * @var iterable<CpShip>
     */
    #[ORM\OneToMany(targetEntity: CpShip::class, mappedBy: 'fleet')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public iterable $ships;

    #[ORM\ManyToOne(targetEntity: CpShip::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?CpShip $flagship = null;

    /**
     * The `[type, id]` pairs of the ships and crews the fleet names as its vessels.
     *
     * @var list<array{0: string, 1: string}>
     */
    #[ORM\Column(type: 'json')]
    public array $vessels = [];

    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'NONE')]
        #[ORM\Column(type: 'string')]
        public ?string $id = null,
        #[ORM\Column(type: 'string')]
        public string $name = '',
    ) {
        $this->ships = new ArrayCollection();
    }
}
