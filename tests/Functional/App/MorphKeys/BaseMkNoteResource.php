<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Filter\WhereMorph;
use haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\Doctrine\MkNoteEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\Doctrine\MkPinEntity;

/**
 * Notes about a counter, a badge, a token or a code, and pinned to others.
 */
abstract class BaseMkNoteResource extends AbstractResource
{
    public static string $type = 'mkNotes';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            MorphTo::make('subject', ['mkCounters', 'mkBadges', 'mkTokens', 'mkCodes'])
                ->identifiedByColumns(type: 'subjectType', id: 'subjectId')
                ->nullable(),
            MorphToMany::make('pins', ['mkCounters', 'mkBadges', 'mkTokens', 'mkCodes'])
                ->through(MkPinEntity::class)
                ->identifiedByRows(owner: 'note', type: 'pinType', id: 'pinId')
                ->cannotBeIncluded()
                ->withoutRelatedEndpoint(),
        ];
    }

    public function filters(): array
    {
        return [
            WhereMorph::make('subject'),
            WhereMorph::make('pinned', 'pins'),
        ];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof MkNoteEntity ? static::$type : '';
    }
}
