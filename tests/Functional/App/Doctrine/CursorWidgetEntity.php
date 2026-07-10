<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped `cursorWidgets` entity for the cursor (keyset) conformance
 * suite — the same shape as the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\CursorWidget}, persisted to
 * the test SQLite database. The id is store-provided (an `AUTO` integer the
 * database assigns on insert), the keyset primary key.
 *
 * `priority` is a NULLABLE int (the null-branch ground truth) and `releasedAt` a
 * NULLABLE datetime (so the keyset binds a date boundary with the column's DBAL
 * type, not a lexical string). Not `final` so Doctrine may proxy it.
 *
 * The NULLABLE `group` ManyToOne is the owning side of {@see CursorGroupEntity}'s inverse
 * `OneToMany widgets` — the owning FK the inverse-FK cursor include window partitions by.
 * It is nullable so a widget carrying no group (the owning-side ManyToMany shelf fixtures)
 * is unaffected: those rows simply keep `group_id` NULL.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cursor_widget')]
class CursorWidgetEntity
{
    #[ORM\ManyToOne(targetEntity: CursorGroupEntity::class, inversedBy: 'widgets')]
    #[ORM\JoinColumn(name: 'group_id', nullable: true)]
    public ?CursorGroupEntity $group = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $category = '',
        #[ORM\Column(nullable: true)]
        public ?int $priority = null,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $releasedAt = null,
    ) {}
}
