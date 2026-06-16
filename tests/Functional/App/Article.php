<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * A plain domain model fed to the in-memory provider — no base class, no ORM —
 * mirroring the core getting-started example. Every property defaults to the
 * empty string so the in-memory persister can construct a blank instance for the
 * hydrator to populate on create (the same shape as the Doctrine
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntity}).
 *
 * The to-one `author` and to-many `comments` relationships expose the **related
 * objects** directly as public properties: core's
 * {@see \haddowg\JsonApi\Resource\Field\Accessor} reads `$article->author`
 * (an {@see Author}) and `$article->comments` (a list of {@see Comment}s) when
 * building relationship linkage.
 */
final class Article
{
    /**
     * @param list<Comment> $comments
     * @param list<Comment> $featuredComments a second, independent comment list
     *                                        backing the load-aware `lazyComments`
     *                                        relation, mirroring the Doctrine
     *                                        entity's separate `featuredComments`
     *                                        association
     * @param array<string, mixed>|null $address the nested structured `address`
     *                                            attribute (a {@see \haddowg\JsonApi\Resource\Field\Map}),
     *                                            stored as a single member rather than
     *                                            spread across columns; null when unset
     * @param list<Author> $editors the related editor objects backing the
     *                               unidirectional many-to-many `editors` relation,
     *                               distinct from the to-one `author`
     */
    public function __construct(
        public ?int $id = null,
        public string $title = '',
        public string $body = '',
        public string $category = '',
        public ?\DateTimeImmutable $publishedAt = null,
        public ?string $couponCode = null,
        public ?\DateTimeImmutable $expiresAt = null,
        public ?Author $author = null,
        public array $comments = [],
        public array $featuredComments = [],
        public ?array $address = null,
        public array $editors = [],
    ) {}
}
