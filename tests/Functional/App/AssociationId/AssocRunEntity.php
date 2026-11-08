<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\AssociationId;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'assoc_run')]
class AssocRunEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public string $name = '';

    #[ORM\ManyToOne(targetEntity: AssocConfigEntity::class)]
    #[ORM\JoinColumn(name: 'primary_config_id', referencedColumnName: 'campaign_id', nullable: true)]
    public ?AssocConfigEntity $primary = null;

    /**
     * @var Collection<int, AssocConfigEntity>
     */
    #[ORM\ManyToMany(targetEntity: AssocConfigEntity::class, inversedBy: 'runs')]
    #[ORM\JoinTable(name: 'assoc_run_configs')]
    #[ORM\JoinColumn(name: 'run_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'config_id', referencedColumnName: 'campaign_id')]
    public Collection $configs;

    /**
     * @var Collection<int, AssocConfigEntity>
     */
    #[ORM\OneToMany(targetEntity: AssocConfigEntity::class, mappedBy: 'owner')]
    public Collection $ownedConfigs;

    public function __construct()
    {
        $this->configs = new ArrayCollection();
        $this->ownedConfigs = new ArrayCollection();
    }
}
