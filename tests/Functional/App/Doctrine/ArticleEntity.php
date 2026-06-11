<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped article: the same shape as the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Article}, persisted to the
 * test SQLite database. The id is application-assigned (no generator), matching
 * the string ids the fixtures use. Not `final` so Doctrine may proxy it.
 *
 * Relationships (Phase 3 foundation): a to-one `author`
 * ({@see ORM\ManyToOne} to {@see AuthorEntity}) and a to-many `comments`
 * ({@see ORM\OneToMany} mapped by {@see CommentEntity}'s owning side). Core's
 * accessor reads the public `$author` / `$comments` members directly, so the
 * relationship-read path emits the same linkage as the in-memory provider.
 */
#[ORM\Entity]
#[ORM\Table(name: 'article')]
class ArticleEntity
{
    /**
     * @var Collection<int, CommentEntity>
     */
    #[ORM\OneToMany(targetEntity: CommentEntity::class, mappedBy: 'article')]
    public Collection $comments;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public string $id = '',
        #[ORM\Column]
        public string $title = '',
        #[ORM\Column]
        public string $body = '',
        #[ORM\Column]
        public string $category = '',
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $publishedAt = null,
        #[ORM\Column(nullable: true)]
        public ?string $couponCode = null,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $expiresAt = null,
        #[ORM\ManyToOne(targetEntity: AuthorEntity::class)]
        #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true)]
        public ?AuthorEntity $author = null,
    ) {
        $this->comments = new ArrayCollection();
    }
}
