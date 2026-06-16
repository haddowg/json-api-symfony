<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdSource\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The store-provided-id witness entity: its `id` is a database-assigned auto-increment
 * integer ({@see ORM\GeneratedValue}), so a create sets nothing on the id and the DB
 * assigns it on flush — the new store-provided default the auto-UUID was replaced by
 * (bundle ADR 0039). A nullable `marker` association gives the linkage-format witness a
 * relationship to write. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'id_source_counter')]
class CounterEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public string $label = '';

    #[ORM\ManyToOne(targetEntity: MarkerEntity::class)]
    #[ORM\JoinColumn(name: 'marker_id', referencedColumnName: 'id', nullable: true)]
    public ?MarkerEntity $marker = null;
}
