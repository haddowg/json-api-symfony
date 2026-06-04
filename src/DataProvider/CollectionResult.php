<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

/**
 * A provider's answer to a collection fetch: the materialized items (already
 * filtered, sorted, and — when the criteria carried a window — windowed), plus
 * the separately-computed total of the whole filtered collection.
 *
 * {@see $total} is non-null exactly when the fetch was windowed: the handler
 * needs it to build the page (`links`/`meta.page` derive from the total), and
 * it is the count **before** windowing, never `count($items)`. An unpaginated
 * fetch leaves it `null` and the handler renders a plain collection document.
 */
final readonly class CollectionResult
{
    /**
     * @param iterable<object> $items
     */
    public function __construct(
        public iterable $items,
        public ?int $total = null,
    ) {}
}
