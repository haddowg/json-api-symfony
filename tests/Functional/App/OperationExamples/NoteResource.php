<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OperationExamples;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Every field declares an example, so each operation of `notes` gets a generated one.
 * The concrete classes declare the overrides on their attributes.
 */
abstract class NoteResource extends AbstractResource
{
    public static string $type = 'notes';

    public function fields(): array
    {
        return [
            Id::make()->example('7'),
            Str::make('title')->example('Groceries'),
            Integer::make('rating')->example(4),
            Str::make('author')->readOnly()->example('system'),
            BelongsTo::make('parent', 'notes')->nullable(),
        ];
    }
}
