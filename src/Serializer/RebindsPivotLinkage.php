<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;

/**
 * The shared pivot-linkage rebind both pivot parent-serializer decorators use: given
 * a base resolver (the Server) and a {@see PivotMetaSerializer} holding the per-member
 * pivot map for one relation, it returns the relationship callable core invokes for
 * that relation name — rebuilding the relationship through the relation's public
 * {@see RelationInterface::buildRelationship()} with a {@see PivotSubstitutingResolver}
 * that binds the {@see PivotMetaSerializer} for the related type, so each linkage
 * identifier carries its `meta.pivot` (riding core's identifier-meta render path with
 * no core change).
 *
 * {@see PivotParentSerializer} rebinds ONE relation over the WHOLE association's map
 * (the relationship-linkage endpoint); {@see PivotLinkageParentSerializer} rebinds
 * EACH pivot relation over its parent's slice of a batched map (a primary-resource
 * document). The substitution is identical, so it lives here once.
 */
trait RebindsPivotLinkage
{
    /**
     * The relationship callable for `$relation`, rebuilding its linkage through a
     * {@see PivotSubstitutingResolver} over `$pivotSerializer` so each identifier
     * carries its pivot meta. The callable matches the core relationship-builder
     * signature core invokes from a serializer's `getRelationships()` map.
     *
     * @return \Closure(mixed, JsonApiRequestInterface, string): AbstractRelationship
     */
    protected function pivotLinkageBuilder(
        RelationInterface $relation,
        SerializerResolverInterface $resolver,
        PivotMetaSerializer $pivotSerializer,
    ): \Closure {
        $substituting = new PivotSubstitutingResolver(
            $resolver,
            $relation->relatedTypes()[0] ?? '',
            $pivotSerializer,
        );

        return static fn(mixed $model, JsonApiRequestInterface $req, string $name): AbstractRelationship => $relation->buildRelationship($model, $req, $substituting);
    }
}
