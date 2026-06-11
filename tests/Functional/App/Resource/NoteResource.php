<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * One member type of a {@see \haddowg\JsonApiBundle\Tests\Functional\App\Board}'s
 * polymorphic relationships: a `notes` resource with an id and a `text`
 * attribute. The {@see DiscriminatesBoardItem} trait makes `getType` object-aware
 * so the polymorphic serializer resolution discriminates a note from an image.
 */
final class NoteResource extends AbstractResource
{
    use DiscriminatesBoardItem;

    public static string $type = 'notes';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('text'),
        ];
    }
}
