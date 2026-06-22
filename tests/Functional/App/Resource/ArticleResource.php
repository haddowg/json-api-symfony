<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\Field\BelongsTo;

/**
 * The in-memory kernel's `articles` resource: the shared declaration served by
 * the {@see \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider}. It is
 * autoconfigured to the resource tag by the test kernel.
 *
 * It extends the shared declaration with one extra to-one relation, `editor`,
 * that opts out of the convention links via {@see BelongsTo::withoutLinks()}
 * while still rendering linkage data. It is backed by the same `author` model
 * property ({@see BelongsTo::storedAs()}), so no model change is needed; it
 * exists only to witness the `withoutLinks()` opt-out on a read (asserted by the
 * in-memory relationship-read suite). The shared `author`/`comments` relations
 * — which keep their convention links — are untouched.
 */
final class ArticleResource extends BaseArticleResource
{
    public function fields(): array
    {
        return [
            ...parent::fields(),
            BelongsTo::make('editor', 'authors')->storedAs('author')->withoutLinks(),
        ];
    }
}
