<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FirstPage;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The desk served as the `fp-desks` singleton at `/desk`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fp_desk')]
class FpDesk
{
    /**
     * @var Collection<int, FpCard>
     */
    #[ORM\OneToMany(targetEntity: FpCard::class, mappedBy: 'desk')]
    public Collection $cards;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public ?int $id = null,
        #[ORM\Column(type: 'string')]
        public string $name = '',
    ) {
        $this->cards = new ArrayCollection();
    }
}
