<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * A plain note model fed to the in-memory provider — one possible member of a
 * {@see Board}'s polymorphic `pinned` (MorphTo) and `items` (MorphToMany)
 * relationships. A POPO with public scalar properties, read by core's
 * {@see \haddowg\JsonApi\Resource\Field\Accessor}; the
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Resource\NoteResource}'s
 * object-aware `getType` discriminates it from an {@see Image}.
 */
final class Note
{
    public function __construct(
        public string $id = '',
        public string $text = '',
    ) {}
}
