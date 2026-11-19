<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WithoutValidator;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A required title, which nothing enforces without the validator, and a count.
 */
final class TypedNoteResource extends AbstractResource
{
    public static string $type = 'typedNotes';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->maxLength(5),
            Integer::make('count'),
        ];
    }
}
