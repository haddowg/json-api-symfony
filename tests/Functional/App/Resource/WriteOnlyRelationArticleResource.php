<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\Field\HasMany;

/**
 * The shared `articles` declaration for the write-only relation suite: the
 * {@see BaseArticleResource} vocabulary plus `secretComments`, a `withData()` to-many
 * over the unique `pinnedComments` column declared `writeOnly()`. Being `withData()`,
 * it would be windowed on every Relationship Queries read if the read path still saw
 * it, and its unique column makes any such load easy to spot in the SQL log.
 *
 * Not `final` so the Doctrine variant inherits the same declarations.
 */
class WriteOnlyRelationArticleResource extends BaseArticleResource
{
    public function fields(): array
    {
        return [
            ...parent::fields(),
            HasMany::make('secretComments', 'comments')->storedAs('pinnedComments')->withData()->writeOnly(),
        ];
    }
}
