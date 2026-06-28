<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * A parent-serializer decorator that makes the **relationship-linkage** endpoint
 * (`GET /{type}/{id}/relationships/{rel}`) render a `belongsToMany` pivot relation's
 * per-member pivot values as identifier `meta.pivot`.
 *
 * That endpoint renders the parent through the parent's serializer with the
 * requested relationship name, so the linkage is built by the parent serializer's
 * `getRelationships()` resolving the related type's serializer. This decorator
 * delegates everything to the inner parent serializer (including every optional
 * serializer-render interface, via {@see AbstractPivotParentSerializer}) EXCEPT that
 * ONE relationship: for it, the relationship value object is rebuilt via the
 * relation's public {@see RelationInterface::buildRelationship()} with a
 * {@see PivotSubstitutingResolver} that returns the {@see PivotMetaSerializer} for
 * the related type — so the linkage identifiers carry their pivot meta. Every other
 * relationship keeps the inner serializer's callable untouched.
 *
 * The pivot map covers the WHOLE association (the relationship endpoint renders all
 * linkage off the parent, not a windowed page), so the provider supplies a full map
 * for this render.
 */
final class PivotParentSerializer extends AbstractPivotParentSerializer
{
    /**
     * @param SerializerInterface          $inner            the real parent serializer
     * @param string                       $relationshipName the pivot relationship being rendered
     * @param RelationInterface            $relation         that relation
     * @param SerializerResolverInterface  $resolver         the base resolver (the Server)
     * @param PivotMetaSerializer          $pivotSerializer  wraps the related serializer + the full pivot map
     */
    public function __construct(
        private readonly SerializerInterface $inner,
        private readonly string $relationshipName,
        private readonly RelationInterface $relation,
        private readonly SerializerResolverInterface $resolver,
        private readonly PivotMetaSerializer $pivotSerializer,
    ) {}

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        $relationships = $this->inner->getRelationships($object, $request);

        $relationships[$this->relationshipName] = $this->pivotLinkageBuilder(
            $this->relation,
            $this->resolver,
            $this->pivotSerializer,
        );

        return $relationships;
    }

    protected function inner(): SerializerInterface
    {
        return $this->inner;
    }
}
