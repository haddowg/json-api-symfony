<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MethodNotAllowed;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mna_advertiser')]
class Advertiser
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $name = '';

    /** @var Collection<int, Agency> */
    #[ORM\ManyToMany(targetEntity: Agency::class)]
    #[ORM\JoinTable(name: 'mna_advertiser_agency')]
    public Collection $agencies;

    public function __construct()
    {
        $this->agencies = new ArrayCollection();
    }
}
