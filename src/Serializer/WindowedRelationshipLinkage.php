<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\RelationshipLinkage;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationshipLinkage;
use haddowg\JsonApi\Serializer\RelationshipLinkageInterface;
use haddowg\JsonApi\Serializer\ToOneRelationshipLinkageInterface;

/**
 * The bundle's fill of core's {@see RelationshipLinkageInterface} seam: a render-time
 * lookup over a map the {@see \haddowg\JsonApiBundle\DataProvider\RelationshipWindowBatcher}
 * pre-computed for the fetched page of parents under the Relationship Queries profile,
 * so a windowed to-many's linkage `data` is the page-1 filtered/sorted set the batcher
 * fetched — WITHOUT the batcher writing that page back onto the parent's relation
 * property (bundle ADR 0086).
 *
 * The write-back was destructive: core reads every relation's linkage off its backing
 * column, so a windowed relation's filtered page overwrote the column a SIBLING relation
 * sharing it (a not-windowed lazy bystander, or another windowed relation) also reads —
 * leaking the windowed page onto a relation the client never addressed (and, on Doctrine,
 * flipping the load-state predicate to "loaded" so a lazy bystander emitted a `data`
 * member the lazy default would have omitted). Supplying the page through this seam
 * leaves the parent property untouched, so a column-sharing bystander renders its own
 * membership.
 *
 * Keyed by the parent's object identity ({@see \spl_object_id()}) then by relation
 * name — exactly as {@see WindowedRelationshipPagination} is — because the very object
 * instances the batcher windowed are the ones the serializer renders. A parent/relation
 * absent from the map (a to-one, a relation not windowed, or a provider that supplied no
 * page) returns `null`, and core then reads that relation's linkage off the model as
 * before.
 *
 * Injected per request behind the {@see RequestScopedRelationshipLinkage} holder (which
 * the handler swaps in only for a profile read), so it lives only for the render of the
 * page it was built from.
 */
final class WindowedRelationshipLinkage implements ToOneRelationshipLinkageInterface
{
    /**
     * @param array<int, array<string, RelationshipLinkage>>      $linkages `spl_object_id(parent) => [relationName => linkage]`
     * @param array<int, array<string, ToOneRelationshipLinkage>> $toOne    `spl_object_id(parent) => [relationName => target]`, the scoped to-one targets (bundle ADR 0125)
     */
    public function __construct(
        private readonly array $linkages,
        private readonly array $toOne = [],
    ) {}

    /**
     * This map with `$other`'s entries added; an entry both carry keeps this one's.
     */
    public function with(self $other): self
    {
        $linkages = $this->linkages;
        foreach ($other->linkages as $objectId => $relations) {
            $linkages[$objectId] = ($linkages[$objectId] ?? []) + $relations;
        }

        $toOne = $this->toOne;
        foreach ($other->toOne as $objectId => $relations) {
            $toOne[$objectId] = ($toOne[$objectId] ?? []) + $relations;
        }

        return new self($linkages, $toOne);
    }

    public function toOneLinkageForRelationship(
        mixed $model,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): ?ToOneRelationshipLinkage {
        if (!\is_object($model)) {
            return null;
        }

        return $this->toOne[\spl_object_id($model)][$relation->name()] ?? null;
    }

    public function linkageForRelationship(
        mixed $model,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): ?RelationshipLinkage {
        if (!\is_object($model)) {
            return null;
        }

        return $this->linkages[\spl_object_id($model)][$relation->name()] ?? null;
    }
}
