<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use haddowg\JsonApi\Resource\Sort\SortByField;

/**
 * Posts in memory also sort by their first note's title, a to-one read through the notes'
 * polymorphic foreign key, which the Doctrine provider can't join as one row per post.
 */
final class InMemoryImPostResource extends ImPostResource
{
    public function sorts(): array
    {
        return [SortByField::make('firstNoteTitle', 'title')->through('firstNote')];
    }
}
