<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped comment: one member of an {@see ArticleEntity}'s to-many
 * `comments` association, the same shape as the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Comment}. It owns the
 * foreign key (`ManyToOne` to the article); the id is application-assigned.
 * Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'comment')]
class CommentEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public string $id = '',
        #[ORM\Column]
        public string $body = '',
        #[ORM\ManyToOne(targetEntity: ArticleEntity::class, inversedBy: 'comments')]
        #[ORM\JoinColumn(name: 'article_id', referencedColumnName: 'id', nullable: true)]
        public ?ArticleEntity $article = null,
        // A second, independent to-many owning side: the article whose
        // `featuredComments` collection this comment belongs to. It exists only
        // so the load-aware `lazyComments` relation has a to-many association no
        // eager relation reads — so the collection stays an uninitialised
        // PersistentCollection through a plain fetch, making the omission case a
        // deterministic functional assertion.
        #[ORM\ManyToOne(targetEntity: ArticleEntity::class, inversedBy: 'featuredComments')]
        #[ORM\JoinColumn(name: 'featured_article_id', referencedColumnName: 'id', nullable: true)]
        public ?ArticleEntity $featuredArticle = null,
    ) {}
}
