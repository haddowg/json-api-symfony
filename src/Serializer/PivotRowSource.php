<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

/**
 * Where a pivot relation's stored values came from: the parent whose relation renders and
 * the provider that read its rows, named when a member has no row.
 */
final readonly class PivotRowSource
{
    public function __construct(
        public string $parentType,
        public string $parentId,
        public string $provider,
    ) {}
}
