<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped post — its to-one `author` association points at a
 * {@see MemberEntity}, but {@see DoctrinePostResource} declares the relation's
 * target as the curated `public-members` type. The in-memory twin is
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\MultiType\Post}.
 *
 * The id is store-provided. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'multitype_post')]
class PostEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $title = '',
        #[ORM\ManyToOne(targetEntity: MemberEntity::class)]
        #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true)]
        public ?MemberEntity $author = null,
    ) {}
}
