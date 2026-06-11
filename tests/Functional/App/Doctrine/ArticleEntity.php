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

    /**
     * A second, independent to-many of comments, mapped by the comment's
     * `featuredArticle` owning side. The load-aware `lazyComments` relation reads
     * this collection (not `comments`), so no eager relation initialises it during
     * a plain fetch — keeping the uninitialised-PersistentCollection omission case
     * a deterministic functional assertion.
     *
     * @var Collection<int, CommentEntity>
     */
    #[ORM\OneToMany(targetEntity: CommentEntity::class, mappedBy: 'featuredArticle')]
    public Collection $featuredComments;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public string $id = '',
        #[ORM\Column]
        public string $title = '',
        // `body` and `category` are optional `articles` attributes (the resource
        // declares neither `->required()`), so a create may omit them. The reference
        // persister instantiates without invoking the constructor (ADR 0029), so the
        // `= ''` parameter default no longer fills an omitted value — the columns are
        // therefore nullable, matching the resource's optionality.
        #[ORM\Column(nullable: true)]
        public ?string $body = '',
        #[ORM\Column(nullable: true)]
        public ?string $category = '',
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $publishedAt = null,
        #[ORM\Column(nullable: true)]
        public ?string $couponCode = null,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $expiresAt = null,
        /**
         * The nested structured `address` attribute (a
         * {@see \haddowg\JsonApi\Resource\Field\Map}) persisted to a single JSON
         * column rather than spread across per-child columns, mirroring the in-memory
         * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Article}'s `$address`.
         *
         * @var array<string, mixed>|null
         */
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $address = null,
        #[ORM\ManyToOne(targetEntity: AuthorEntity::class)]
        #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true)]
        public ?AuthorEntity $author = null,
    ) {
        $this->comments = new ArrayCollection();
        $this->featuredComments = new ArrayCollection();
    }
}
