<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * The in-memory `publishers` model of the flattened-attribute (`on()`) conformance
 * fixture (bundle ADR 0085): a sibling registered type a {@see FlattenBook} carries a
 * `publisher` FK to. It backs no flattened attribute and is no longer eager-pinned —
 * it stays only to keep the seeded book graph realistic. A POPO read by core's
 * {@see \haddowg\JsonApi\Resource\Field\Accessor}.
 */
final class FlattenPublisher
{
    public function __construct(
        public ?int $id = null,
        public string $name = '',
    ) {}
}
