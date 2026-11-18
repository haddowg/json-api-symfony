<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphGate;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Posts whose `subject` renders from the stored type and id columns. Neither candidate
 * overrides getType(), so only the pair can say which type a subject is.
 */
#[AsJsonApiResource(readOnly: true)]
class GatedPostResource extends AbstractResource
{
    public static string $type = 'gatedPosts';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            MorphTo::make('subject', ['gatedArticles', 'gatedVideos'])
                ->identifiedByColumns(type: 'subjectType', id: 'subjectId')
                ->readOnly(),
        ];
    }
}
