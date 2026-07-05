<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped composite widget: the same shape as the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Composite\CompositeWidget},
 * with each composite attribute (`address` = Obj, `block` = OneOf, `contact` =
 * ArrayHash+Shape) stored as a **single `json` column** — the storage decision
 * for composite types: one value in, one value out, scalar children (a value
 * needing a richer PHP type inside a composite rides the field's
 * `serializeUsing`/`fillUsing` escape hatch, not a custom DBAL type). Not
 * `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'composite_widget')]
class CompositeWidgetEntity
{
    /**
     * @param array<string, mixed>|null $address
     * @param array<string, mixed>|null $block
     * @param array<string, mixed>|null $contact
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $address = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $block = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $contact = null,
    ) {}
}
