<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphMany;
use haddowg\JsonApi\Resource\Field\MorphOne;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereThrough;

/**
 * Reads its notes back through the notes' polymorphic `subject` with `MorphMany` and
 * `MorphOne`, so a note of the other owner type with the same id never counts.
 */
abstract class ImPostResource extends AbstractResource
{
    public static string $type = 'im-posts';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('title'),
            MorphMany::make('notes', 'im-notes', 'subject')->withData()->countable(),
            MorphOne::make('firstNote', 'im-notes', 'subject')->withData(),
        ];
    }

    public function filters(): array
    {
        return [
            WhereHas::make('hasNotes', 'notes'),
            WhereDoesntHave::make('noNotes', 'notes'),
            WhereThrough::make('noteTitle', 'notes.title'),
        ];
    }
}
