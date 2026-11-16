<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\GateOrder;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The resource the gate-order suite writes. One class serves both kernels: Doctrine maps
 * it, and the in-memory store holds it as it is.
 */
#[ORM\Entity]
#[ORM\Table(name: 'gate_widget')]
class GateWidget
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public string $name = '';

    #[ORM\Column]
    public string $owner = '';

    #[ORM\ManyToOne(targetEntity: self::class)]
    public ?GateWidget $partner = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    public ?GateWidget $parent = null;

    /**
     * @var Collection<int, GateWidget>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    public Collection $children;

    public function __construct(string $name = '', string $owner = '')
    {
        $this->name = $name;
        $this->owner = $owner;
        $this->children = new ArrayCollection();
    }
}
