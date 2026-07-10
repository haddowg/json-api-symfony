<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped `cursorGroups` parent for the cursor (keyset) INCLUDE conformance
 * suite — the same shape as the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\CursorGroup}.
 *
 * `widgets` is the INVERSE side of a `OneToMany` (`mappedBy: group`), so the related
 * fetch takes the {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\RelationScope}
 * inverse-FK fast-path — the related {@see CursorWidgetEntity} carries the owning
 * `group_id` FK, so the cursor include window roots on the widget and partitions by that
 * FK (no join table), the complement of the owning-side ManyToMany
 * {@see CursorShelfEntity} exercises. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cursor_group')]
class CursorGroupEntity
{
    /** @var Collection<int, CursorWidgetEntity> */
    #[ORM\OneToMany(targetEntity: CursorWidgetEntity::class, mappedBy: 'group')]
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
