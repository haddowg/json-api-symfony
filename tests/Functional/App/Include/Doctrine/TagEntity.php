<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Include\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped `tags` entity for the include-safeguards suite (the same
 * shape as the in-memory {@see \haddowg\JsonApiBundle\Tests\Functional\App\Include\Tag}).
 * Its `node` `ManyToOne` is includable from the `tags` root — the contrast the
 * Capability C allow-list headline relies on. Application-assigned id; not `final`
 * so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inc_tag')]
class TagEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public string $id = '',
        #[ORM\Column]
        public string $name = '',
        #[ORM\ManyToOne(targetEntity: NodeEntity::class)]
        #[ORM\JoinColumn(name: 'node_id', referencedColumnName: 'id', nullable: true)]
        public ?NodeEntity $node = null,
    ) {}
}
