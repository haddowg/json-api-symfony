<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Serializer\RelationshipCountInterface;

/**
 * The bundle's fill of core's {@see RelationshipCountInterface} count seam (core
 * ADR 0057): a render-time lookup over a map the
 * {@see \haddowg\JsonApiBundle\DataProvider\RelationCountBatcher} pre-computed for
 * the fetched page of parents, so the per-relationship `meta.total` core renders
 * for a `?withCount`-named countable relation reads a batched count rather than
 * triggering a per-object query (bundle ADR 0052).
 *
 * The map is keyed by the parent's object identity ({@see \spl_object_id()}) then
 * by relation name, because the very object instances the batcher counted are the
 * ones the serializer renders (the response value object holds them) — so the
 * lookup needs no wire-id re-resolution and is exact even for two distinct parents
 * that happen to share a wire id across types. A parent/relation absent from the
 * map (not counted, or not a countable `?withCount` relation) returns `null`, and
 * core then omits `meta.total` for it.
 *
 * Injected per request through core's
 * {@see \haddowg\JsonApi\Server\Server::withRelationshipCount()} (mirroring how the
 * Doctrine load-state predicate rides
 * {@see \haddowg\JsonApi\Server\Server::withRelationshipLoadState()}), so it lives
 * only for the render of the page it was built from.
 */
final class BatchedRelationshipCount implements RelationshipCountInterface
{
    /**
     * @param array<int, array<string, int>> $counts `spl_object_id(parent) => [relationName => count]`
     */
    public function __construct(private readonly array $counts) {}

    public function countRelationship(mixed $model, RelationInterface $relation): ?int
    {
        if (!\is_object($model)) {
            return null;
        }

        return $this->counts[\spl_object_id($model)][$relation->name()] ?? null;
    }
}
