<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped panel, the same shape as the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\FieldSelection\Panel}: each object
 * attribute is one `json` column. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'field_selection_panel')]
class PanelEntity
{
    /**
     * @param array<string, mixed>|null $values
     * @param array<string, mixed>|null $groups
     * @param array<string, mixed>|null $settings
     * @param array<string, mixed>|null $address
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
        #[ORM\Column(name: 'panel_values', type: 'json', nullable: true)]
        public ?array $values = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $groups = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $settings = null,
        #[ORM\ManyToOne(targetEntity: CuratorEntity::class)]
        public ?CuratorEntity $curator = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $address = null,
    ) {}
}
