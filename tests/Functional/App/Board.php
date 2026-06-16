<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * A plain board model fed to the in-memory provider, the parent of both
 * polymorphic relationships the witness exercises:
 *  - `pinned` — a polymorphic to-one ({@see \haddowg\JsonApi\Resource\Field\MorphTo}):
 *    a {@see Note} or an {@see Image} (or `null`);
 *  - `items` — a polymorphic to-many ({@see \haddowg\JsonApi\Resource\Field\MorphToMany}):
 *    a mixed `list` of {@see Note}/{@see Image} members.
 *
 * Both expose the **related objects** directly as public properties so core's
 * {@see \haddowg\JsonApi\Resource\Field\Accessor} reads them when building
 * linkage; the per-object serializer resolution discriminates the member types.
 */
final class Board
{
    /**
     * @param list<Note|Image> $items the mixed-type members of the polymorphic
     *                                 to-many `items` relationship, in order
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public ?object $pinned = null,
        public array $items = [],
    ) {}
}
