<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * A plain comment model fed to the in-memory provider — one member of an
 * {@see Article}'s to-many `comments` relationship. A POPO with public scalar
 * properties, read by core's {@see \haddowg\JsonApi\Resource\Field\Accessor}.
 *
 * The optional `$article` back-reference mirrors the Doctrine
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CommentEntity}'s
 * owning-side `ManyToOne` to its article, so the multi-hop traversal filter
 * `comments.article.title` ({@see \haddowg\JsonApi\Resource\Filter\WhereThrough})
 * walks the same chain on the in-memory provider as the Doctrine subquery does —
 * a precondition for the dual-provider byte-parity of the multi-hop case.
 */
final class Comment
{
    public function __construct(
        public ?int $id = null,
        public string $body = '',
        public ?Article $article = null,
    ) {}
}
