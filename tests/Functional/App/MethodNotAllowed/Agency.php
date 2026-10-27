<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MethodNotAllowed;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mna_agency')]
class Agency
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $name = '';

    /** @var Collection<int, Advertiser> */
    #[ORM\ManyToMany(targetEntity: Advertiser::class)]
    #[ORM\JoinTable(name: 'mna_agency_advertiser')]
    public Collection $advertisers;

    public function __construct()
    {
        $this->advertisers = new ArrayCollection();
    }
}
