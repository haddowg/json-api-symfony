<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped `tags` entity backing the genericity witness — the same
 * shape as the in-memory {@see \haddowg\JsonApiBundle\Tests\Functional\App\Tag},
 * with an optional `ManyToOne` to {@see ArticleEntity}. The id is store-provided
 * — an `AUTO` integer the database assigns on insert (a create omits it). Not
 * `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tag')]
class TagEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
        #[ORM\ManyToOne(targetEntity: ArticleEntity::class)]
        #[ORM\JoinColumn(name: 'article_id', referencedColumnName: 'id', nullable: true)]
        public ?ArticleEntity $article = null,
    ) {}
}
