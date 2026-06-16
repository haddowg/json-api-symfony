<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Include\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped holder for the two root-scoped include-safeguard witnesses
 * (`roots` and `caps`), the same shape as the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Include\Holder}. One entity
 * class backs both types (their JSON:API resources differ in policy, not storage);
 * the table carries a `kind` discriminator column purely so a single SQLite table
 * can seed both `roots` and `caps` rows. Application-assigned id; not `final` so
 * Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inc_holder')]
class HolderEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public string $id = '',
        #[ORM\Column]
        public string $label = '',
        #[ORM\Column]
        public string $kind = 'root',
        #[ORM\ManyToOne(targetEntity: NodeEntity::class)]
        #[ORM\JoinColumn(name: 'node_id', referencedColumnName: 'id', nullable: true)]
        public ?NodeEntity $node = null,
    ) {}
}
