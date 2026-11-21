<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'decimal_tray')]
class TrayEntity
{
    /**
     * @var Collection<int, TrayLineEntity>
     */
    #[ORM\OneToMany(targetEntity: TrayLineEntity::class, mappedBy: 'tray', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $lines;

    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column(type: 'string')]
        public string $name = '',
    ) {
        $this->lines = new ArrayCollection();
    }
}
