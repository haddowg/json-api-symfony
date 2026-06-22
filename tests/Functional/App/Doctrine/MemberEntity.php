<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped member — the entity backing TWO JSON:API types in the
 * multi-type-per-entity conformance suite:
 *  - {@see DoctrineMemberResource} (`members`, the full view), and
 *  - {@see DoctrinePublicMemberResource} (`public-members`, the curated view).
 *
 * Both resources declare `#[AsJsonApiResource(entity: MemberEntity::class)]`. The
 * bundle's type→entity map tolerates two types → one entity (it only rejects one
 * type → two entities), so each type resolves the same row through the same Doctrine
 * provider. The in-memory twin is
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\MultiType\Member}.
 *
 * The id is store-provided (an `AUTO` integer). Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'multitype_member')]
class MemberEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column(name: 'display_name')]
        public string $displayName = '',
        #[ORM\Column]
        public string $email = '',
        #[ORM\Column(name: 'secret_note')]
        public string $secretNote = '',
    ) {}
}
