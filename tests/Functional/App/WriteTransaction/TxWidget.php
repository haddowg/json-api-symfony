<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The resource the write-transaction suite writes. One class serves both kernels: Doctrine
 * maps it, and the in-memory store holds it as it is.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tx_widget')]
class TxWidget
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(unique: true)]
    public string $name = '';

    /**
     * @var Collection<int, TxNote>
     */
    #[ORM\ManyToMany(targetEntity: TxNote::class)]
    #[ORM\JoinTable(name: 'tx_widget_note')]
    public Collection $notes;

    public function __construct()
    {
        $this->notes = new ArrayCollection();
    }
}
