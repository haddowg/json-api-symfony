<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApiBundle\Tests\Functional\App\Image;

/**
 * An object-aware `getType` shared by the two member resources of a {@see \haddowg\JsonApiBundle\Tests\Functional\App\Board}'s
 * polymorphic relationships. It overrides {@see \haddowg\JsonApi\Resource\AbstractResource::getType()}
 * (which returns the static `$type` regardless of the object) so that
 * {@see \haddowg\JsonApi\Resource\Field\RelationInterface::resolveSerializer()},
 * which discriminates by matching a member object's own `getType()` against each
 * declared type, picks the right serializer per member — `images` for an
 * {@see Image}, `notes` otherwise.
 */
trait DiscriminatesBoardItem
{
    public function getType(mixed $object): string
    {
        return $object instanceof Image ? 'images' : 'notes';
    }
}
