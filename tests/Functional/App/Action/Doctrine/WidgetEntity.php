<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action\Doctrine;

use Doctrine\ORM\Mapping as ORM;
use haddowg\JsonApiBundle\Tests\Functional\App\Action\MutableWidget;

/**
 * The Doctrine-mapped `actionWidgets` entity: the persisted twin of the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Action\Widget}, so the
 * custom-action conformance suite runs identical assertions against both providers
 * (bundle ADR 0076, design §10). It implements {@see MutableWidget} so the
 * storage-agnostic action handlers mutate it through the same interface. The id is
 * store-provided (an `AUTO` integer). Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'action_widget')]
class WidgetEntity implements MutableWidget
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
        #[ORM\Column(type: 'boolean')]
        public bool $published = false,
        #[ORM\Column(type: 'string', nullable: true)]
        public ?string $uploadedArtwork = null,
    ) {}

    public function widgetName(): string
    {
        return $this->name;
    }

    public function applyName(string $name): void
    {
        $this->name = $name;
    }

    public function publish(): void
    {
        $this->published = true;
    }

    public function attachArtwork(string $artwork): void
    {
        $this->uploadedArtwork = $artwork;
    }
}
