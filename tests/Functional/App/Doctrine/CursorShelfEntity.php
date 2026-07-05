<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped `cursorShelves` parent for the related-collection cursor
 * (keyset) conformance suite — the same shape as the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\CursorShelf}, persisted to
 * the test SQLite database.
 *
 * `widgets` is an OWNING-side unidirectional `ManyToMany` (a join table, no FK
 * on {@see CursorWidgetEntity}), so the related fetch takes the
 * {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\RelationScope} IN-subquery
 * branch — proving the keyset push-down composes with the subquery parent scope,
 * not just the inverse-FK fast-path. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cursor_shelf')]
class CursorShelfEntity
{
    /** @var Collection<int, CursorWidgetEntity> */
    #[ORM\ManyToMany(targetEntity: CursorWidgetEntity::class)]
    #[ORM\JoinTable(name: 'cursor_shelf_widget')]
    public Collection $widgets;

    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
    ) {
        $this->widgets = new ArrayCollection();
    }
}
