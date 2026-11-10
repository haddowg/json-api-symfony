<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\LoadedLinkage;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'loaded_linkage_template')]
class LinkageTemplate
{
    /**
     * @var Collection<int, LinkageBinding>
     */
    #[ORM\OneToMany(targetEntity: LinkageBinding::class, mappedBy: 'template')]
    private Collection $bindings;

    /**
     * @var Collection<int, LinkageBinding>
     */
    #[ORM\ManyToMany(targetEntity: LinkageBinding::class)]
    #[ORM\JoinTable(name: 'loaded_linkage_template_shared')]
    private Collection $sharedBindings;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(type: 'string')]
        public string $name,
    ) {
        $this->bindings = new ArrayCollection();
        $this->sharedBindings = new ArrayCollection();
    }

    /**
     * @return Collection<int, LinkageBinding>
     */
    public function getBindings(): Collection
    {
        return $this->bindings;
    }

    /**
     * @return Collection<int, LinkageBinding>
     */
    public function getSharedBindings(): Collection
    {
        return $this->sharedBindings;
    }

    public function addBinding(LinkageBinding $binding): void
    {
        $this->bindings->add($binding);
        $this->sharedBindings->add($binding);
    }
}
