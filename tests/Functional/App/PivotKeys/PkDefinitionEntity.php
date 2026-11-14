<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A placeholder definition keyed by a binary UUID its constructor mints.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pk_definition')]
class PkDefinitionEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    public Uuid $id;

    #[ORM\Column]
    public string $name = '';

    /**
     * @var Collection<int, PkTemplatePlaceholderEntity>
     */
    #[ORM\OneToMany(targetEntity: PkTemplatePlaceholderEntity::class, mappedBy: 'definition')]
    public Collection $usages;

    public function __construct(?Uuid $id = null)
    {
        $this->id = $id ?? Uuid::v7();
        $this->usages = new ArrayCollection();
    }
}
