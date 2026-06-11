<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * A plain `posts` model — the resource-less type whose relations are declared
 * standalone via {@see PostRelations} (ADR 0026). Its wire shape comes from the
 * standalone {@see PostSerializer}; its `author` / `comments` relations come off
 * these properties, rendered by core's `RendersRelationsTrait` through the
 * standalone relations.
 */
final class Post
{
    /**
     * @param list<Comment> $comments
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public ?Author $author = null,
        public array $comments = [],
    ) {}
}
