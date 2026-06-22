<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The shared `posts` declaration both multi-type kernels serve. Its to-one `author`
 * relation targets the CURATED `public-members` type — the `make()` type `'public-members'` —
 * so a post's author renders `{type: public-members, id}` linkage and `?include=author`
 * expands the curated view, even though the underlying
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\MultiType\Member} is also exposed
 * as the full `members` type.
 *
 * `posts` is writable, so a relationship mutation (`PATCH …/relationships/author`)
 * sending `{type: public-members, id}` resolves the related Member, while a wrong
 * `{type: members, id}` is rejected as a `409` resource-type conflict — the bundle's
 * validator enforces the relation's declared related types
 * ({@see \haddowg\JsonApiBundle\Validation\ResourceValidator}), and the single declared
 * related type here is `public-members`.
 */
abstract class BasePostResource extends AbstractResource
{
    public static string $type = 'posts';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required(),
            // The relation's target TYPE is the curated `public-members`, not the full
            // `members` — choosing a relationship's target type is exactly the `make()` type `'…'`.
            BelongsTo::make('author', 'public-members'),
        ];
    }
}
