<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The pivot between a template and a definition. Its constructor takes no argument
 * and mints the binary UUID id and initialises the `audiences` collection, so a row
 * built without it has neither.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pk_template_placeholder')]
class PkTemplatePlaceholderEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    public Uuid $id;

    #[ORM\ManyToOne(targetEntity: PkTemplateEntity::class, inversedBy: 'placeholderRows')]
    #[ORM\JoinColumn(name: 'templateId', referencedColumnName: 'campaignId', nullable: false)]
    public ?PkTemplateEntity $template = null;

    #[ORM\ManyToOne(targetEntity: PkDefinitionEntity::class, inversedBy: 'usages')]
    #[ORM\JoinColumn(name: 'definitionId', referencedColumnName: 'id', nullable: false)]
    public ?PkDefinitionEntity $definition = null;

    /**
     * The definition's foreign key, mapped read-only beside the association.
     */
    #[ORM\Column(name: 'definitionId', type: 'uuid', insertable: false, updatable: false)]
    public ?Uuid $definitionId = null;

    #[ORM\Column(nullable: true)]
    public ?string $defaultOverride = null;

    /**
     * @var Collection<int, PkCampaignEntity>
     */
    #[ORM\ManyToMany(targetEntity: PkCampaignEntity::class)]
    #[ORM\JoinTable(name: 'pk_placeholder_audience')]
    #[ORM\JoinColumn(name: 'placeholderId', referencedColumnName: 'id')]
    public Collection $audiences;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->audiences = new ArrayCollection();
    }
}
