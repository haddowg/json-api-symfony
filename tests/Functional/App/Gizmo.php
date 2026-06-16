<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * A plain `gizmos` model for the endpoint-exposure witness: a to-one `author` and
 * a to-many `comments`, reused by the suppressed/locked relation variants
 * ({@see \haddowg\JsonApiBundle\Tests\Functional\App\Resource\GizmoResource}). A
 * POPO with public properties, read by core's
 * {@see \haddowg\JsonApi\Resource\Field\Accessor} the same way as {@see Article}.
 */
final class Gizmo
{
    /**
     * @param list<Comment> $comments
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?Author $author = null,
        public array $comments = [],
    ) {}
}
