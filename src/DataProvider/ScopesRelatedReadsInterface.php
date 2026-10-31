<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Tells the {@see RelatedIncludeBatcher} whether a provider's related loads of a
 * relation apply a scope the parent model's own association does not: a Doctrine
 * query extension (soft-delete, tenancy, visibility), or anything else a custom
 * provider filters by.
 *
 * When it answers `true`, an included relation and any linkage `data` that renders
 * for it are loaded through {@see DataProviderInterface::fetchRelatedCollectionBatch()},
 * never read off the parent model, and a relation that cannot be loaded that way is
 * refused with a `\LogicException`. When it answers `false`, reading
 * the relation off the parent returns what the related endpoint returns, so the
 * batcher may leave it lazy.
 *
 * Optional. A provider that does not implement it is treated as scoping every relation.
 */
interface ScopesRelatedReadsInterface
{
    /**
     * Whether loading `$relation`'s related value(s) through this provider can exclude
     * members the parent model's association holds.
     */
    public function scopesRelatedReads(RelationInterface $relation): bool;
}
