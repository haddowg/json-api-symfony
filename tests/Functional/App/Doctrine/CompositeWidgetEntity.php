<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped composite widget: the same shape as the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Composite\CompositeWidget},
 * with each composite attribute (`address` and `schedule` = Obj, `block` = OneOf,
 * `contact` = ArrayHash+Shape) stored as a **single `json` column** — the storage
 * decision for composite types: one value in, one value out. A date child is
 * stored as its date object's JSON dump and core reads that back in the field's
 * format, so it needs no custom DBAL type. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'composite_widget')]
class CompositeWidgetEntity
{
    /**
     * @param array<string, mixed>|null $address
     * @param array<string, mixed>|null $block
     * @param array<string, mixed>|null $schedule
     * @param array<string, mixed>|null $contact
     * @param array<string, mixed>|null $prefs
     * @param array<string, mixed>|null $shape
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
        public ?array $schedule = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $contact = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $prefs = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $shape = null,
    ) {}
}
