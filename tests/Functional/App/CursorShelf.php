<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * The in-memory `cursorShelves` parent for the related-collection cursor
 * (keyset) conformance suite — the same shape as the Doctrine
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CursorShelfEntity}.
 *
 * It carries only the to-many `widgets` members ({@see CursorWidget}); the
 * relation on the resource declares its own {@see \haddowg\JsonApi\Pagination\CursorPaginator},
 * so `GET /cursorShelves/{id}/widgets` pages by keyset scoped to this parent.
 */
final class CursorShelf
{
    /**
     * @param list<CursorWidget> $widgets
     */
    public function __construct(
        public ?int $id = null,
        public array $widgets = [],
    ) {}
}
