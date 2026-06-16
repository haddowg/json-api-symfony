<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The other member type of a {@see \haddowg\JsonApiBundle\Tests\Functional\App\Board}'s
 * polymorphic relationships: an `images` resource with an id and a `url`
 * attribute. The {@see DiscriminatesBoardItem} trait makes `getType` object-aware
 * so the polymorphic serializer resolution discriminates an image from a note.
 */
final class ImageResource extends AbstractResource
{
    use DiscriminatesBoardItem;

    public static string $type = 'images';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('url'),
        ];
    }
}
