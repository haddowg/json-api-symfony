<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security;

use Doctrine\ORM\Mapping as ORM;

/**
 * The `ownedWidgets` entity: gated by an ownership expression
 * `is_granted('EDIT', object)` on its resource, resolved by the {@see OwnedWidgetVoter}
 * which compares `$owner` to the authenticated user's identifier. A self-referential
 * `parent` ({@see ORM\ManyToOne} to another owned widget) gives the resource a
 * relationship whose mutation endpoint exercises the `securityUpdate` gate against the
 * parent. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'owned_widget')]
class OwnedWidgetEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        #[ORM\GeneratedValue]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
        #[ORM\Column]
        public string $owner = '',
        #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true)]
        public ?OwnedWidgetEntity $parent = null,
    ) {}
}
