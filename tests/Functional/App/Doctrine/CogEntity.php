<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The encoded-id witness entity (bundle ADR 0038): keyed by an application-assigned
 * integer storage key the {@see CogResource} never exposes — the wire id is a
 * `cog-…` token the {@see HexIdEncoder} encodes. A self-referential `parent`
 * ({@see ORM\ManyToOne} to another cog) gives a relationship-write linkage whose id
 * is itself an encoded `cogs` token, so the persister's linkage decode is exercised.
 * Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cog')]
class CogEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id = 0,
        #[ORM\Column]
        public string $name = '',
        #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true)]
        public ?CogEntity $parent = null,
    ) {}
}
