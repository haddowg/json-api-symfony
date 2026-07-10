<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * The in-memory `cursorGroups` parent for the cursor (keyset) INCLUDE conformance suite —
 * the same shape as the Doctrine
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CursorGroupEntity}.
 *
 * It carries only the to-many `widgets` members ({@see CursorWidget}); the relation on the
 * resource declares its own {@see \haddowg\JsonApi\Pagination\CursorPaginator}, so a
 * `?include=widgets` pages by keyset per parent — the witness the Doctrine inverse-FK
 * single-window push-down must match byte-for-byte.
 */
final class CursorGroup
{
    /**
     * @param list<CursorWidget> $widgets
     */
    public function __construct(
        public ?int $id = null,
        public array $widgets = [],
    ) {}
}
