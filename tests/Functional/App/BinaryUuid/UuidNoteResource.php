<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Filter\WhereMorph;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: UuidNoteEntity::class)]
final class UuidNoteResource extends AbstractResource
{
    public static string $type = 'notes';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            MorphTo::make('subject', ['labels', 'shelves'])
                ->identifiedByColumns(type: 'subjectType', id: 'subjectId')
                ->nullable(),
        ];
    }

    public function filters(): array
    {
        return [
            WhereMorph::make('subject'),
        ];
    }
}
