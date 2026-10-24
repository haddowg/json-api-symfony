<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;

/**
 * The shared `articles` declaration for the relation URI field name suite: the
 * {@see BaseArticleResource} vocabulary plus two relations served under a URI segment
 * other than their name, `remarks` (a to-many over `comments`) at `article-remarks`
 * and `writer` (a to-one over `author`) at `written-by`.
 *
 * Not `final` so the Doctrine variant inherits the same declarations.
 */
class UriFieldNameArticleResource extends BaseArticleResource
{
    public function fields(): array
    {
        return [
            ...parent::fields(),
            HasMany::make('remarks', 'comments')->storedAs('comments')->withData()->withUriFieldName('article-remarks'),
            BelongsTo::make('writer', 'authors')->storedAs('author')->withUriFieldName('written-by'),
        ];
    }
}
