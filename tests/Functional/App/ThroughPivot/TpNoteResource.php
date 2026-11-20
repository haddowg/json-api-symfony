<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * One of two types serving the notes a pivot row holds, registered only to refuse a path
 * reaching them.
 */
#[AsJsonApiResource(entity: TpNoteEntity::class, readOnly: true)]
final class TpNoteResource extends AbstractResource
{
    public static string $type = 'tpNotes';

    public function fields(): array
    {
        return [Id::make()->numeric(), Str::make('text')];
    }
}
