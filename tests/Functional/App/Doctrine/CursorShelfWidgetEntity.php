<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The association (pivot) entity backing the `cursorShelves.pivotWidgets`
 * `belongsToMany` relation for the pivot-related CURSOR conformance suite
 * (bundle ADR 0114): the two single-valued (`ManyToOne`) sides — `shelf` back
 * to the parent, `widget` to the far type — plus the one pivot column, `slot`
 * (an int the suite sorts by, so the keyset walks a PIVOT-aliased column with
 * the far entity's PK tiebreak).
 *
 * Seeded from the shared
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\CursorShelfFixtures} slot
 * map, so the Doctrine pivot keyset pages assert against the same content the
 * in-memory plain keyset pages walk. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cursor_shelf_widget_pivot')]
class CursorShelfWidgetEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\ManyToOne(targetEntity: CursorShelfEntity::class)]
        public ?CursorShelfEntity $shelf = null,
        #[ORM\ManyToOne(targetEntity: CursorWidgetEntity::class)]
        public ?CursorWidgetEntity $widget = null,
        #[ORM\Column(type: 'integer')]
        public int $slot = 0,
    ) {}
}
