<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * A second type over {@see CommentEntity} that links each comment to its article, so
 * the query-budget suite can include `article` and reach the article level's
 * `withData()` comments one level down.
 */
#[AsJsonApiResource(entity: CommentEntity::class)]
final class ArticleCommentResource extends AbstractResource
{
    public static string $type = 'articleComments';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('body'),
            BelongsTo::make('article', 'articles'),
        ];
    }
}
