<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MultiType;

/**
 * A plain domain model fed to the in-memory provider for the
 * multi-type-per-entity conformance suite. Its to-one `author` points at a
 * {@see Member}, but the `posts` resource declares the relation's target as the
 * CURATED `public-members` type (the `make()` type `'public-members'`) — so the linkage and
 * any include render the author as the public view, not the full `members` type.
 *
 * `posts` is writable, so a relationship mutation (`PATCH …/relationships/author`)
 * sending `{type: public-members, id}` resolves and a wrong `{type: members, id}`
 * is rejected as a `409` resource-type conflict by the bundle's validator (which
 * enforces the relation's declared related types).
 *
 * The Doctrine twin is
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PostEntity}.
 */
final class Post
{
    public function __construct(
        public ?int $id = null,
        public string $title = '',
        public ?Member $author = null,
    ) {}
}
