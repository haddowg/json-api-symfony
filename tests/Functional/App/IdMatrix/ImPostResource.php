<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereThrough;

/**
 * Reads its notes back through the notes' polymorphic `subject`, so a note of the other
 * owner type with the same id never counts.
 */
abstract class ImPostResource extends AbstractResource
{
    public static string $type = 'im-posts';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('title'),
            HasMany::make('notes', 'im-notes', 'subject')->withData()->countable(),
            HasOne::make('firstNote', 'im-notes', 'subject')->withData(),
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
