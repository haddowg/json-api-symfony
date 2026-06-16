<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Include\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped `nodes` entity for the include-safeguards suite: the same
 * shape as the in-memory {@see \haddowg\JsonApiBundle\Tests\Functional\App\Include\Node},
 * persisted to the test SQLite database. The id is application-assigned (no
 * generator); not `final` so Doctrine may proxy it.
 *
 * `next` and `prev` are self-referential `ManyToOne` associations forming the
 * circular forward chain (and its inverse), and `tag` reaches {@see TagEntity}.
 * The provider's batch include-preloader walks these real associations, so the
 * safeguards (non-includable `prev`, the depth cap, the root whitelist) are
 * witnessed against the Doctrine preloader, not only the in-memory accessor.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inc_node')]
class NodeEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public string $id = '',
        #[ORM\Column]
        public string $label = '',
        #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(name: 'next_id', referencedColumnName: 'id', nullable: true)]
        public ?self $next = null,
        #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(name: 'prev_id', referencedColumnName: 'id', nullable: true)]
        public ?self $prev = null,
        #[ORM\ManyToOne(targetEntity: TagEntity::class)]
        #[ORM\JoinColumn(name: 'tag_id', referencedColumnName: 'id', nullable: true)]
        public ?TagEntity $tag = null,
    ) {}
}
