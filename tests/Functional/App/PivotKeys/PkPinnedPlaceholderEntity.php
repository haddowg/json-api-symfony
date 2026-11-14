<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A second pivot between a template and a definition whose constructor requires both
 * ends, so the persister can't call it; the template resource builds its rows.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pk_pinned_placeholder')]
class PkPinnedPlaceholderEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    public Uuid $id;

    #[ORM\Column(nullable: true)]
    public ?string $note = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: PkTemplateEntity::class, inversedBy: 'pinnedRows')]
        #[ORM\JoinColumn(name: 'templateId', referencedColumnName: 'campaignId', nullable: false)]
        public PkTemplateEntity $template,
        #[ORM\ManyToOne(targetEntity: PkDefinitionEntity::class)]
        #[ORM\JoinColumn(name: 'definitionId', referencedColumnName: 'id', nullable: false)]
        public PkDefinitionEntity $definition,
        #[ORM\Column]
        public string $pinnedBy = '',
    ) {
        $this->id = Uuid::v7();
    }
}
