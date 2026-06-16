<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the in-memory `posts` provider for the standalone-relations witness, seeded
 * with one post carrying an {@see Author} and two {@see Comment}s. The related
 * `authors` / `comments` types need no provider of their own — the values come off
 * the parent post.
 */
final class PostFactory
{
    public static function createProvider(): InMemoryDataProvider
    {
        $posts = [
            'p1' => new Post(
                'p1',
                'Hello',
                new Author(1, 'Ada'),
                [new Comment(1, 'First'), new Comment(2, 'Nice')],
            ),
        ];

        return new InMemoryDataProvider('posts', $posts, static function (object $item): string {
            \assert($item instanceof Post);

            return $item->id;
        });
    }
}
