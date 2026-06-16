<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * A plain image model fed to the in-memory provider — the other possible member
 * of a {@see Board}'s polymorphic `pinned` (MorphTo) and `items` (MorphToMany)
 * relationships. A POPO with public scalar properties, read by core's
 * {@see \haddowg\JsonApi\Resource\Field\Accessor}; the
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Resource\ImageResource}'s
 * object-aware `getType` discriminates it from a {@see Note}.
 */
final class Image
{
    public function __construct(
        public string $id = '',
        public string $url = '',
    ) {}
}
