<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;
use haddowg\JsonApi\Serializer\IncludeControlsInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * A parent-serializer decorator that makes the **relationship-linkage** endpoint
 * (`GET /{type}/{id}/relationships/{rel}`) render a `belongsToMany` pivot relation's
 * per-member pivot values as identifier `meta.pivot`.
 *
 * That endpoint renders the parent through the parent's serializer with the
 * requested relationship name, so the linkage is built by the parent serializer's
 * `getRelationships()` resolving the related type's serializer. This decorator
 * delegates everything to the inner parent serializer EXCEPT that ONE relationship:
 * for it, the relationship value object is rebuilt via the relation's public
 * {@see RelationInterface::buildRelationship()} with a {@see PivotSubstitutingResolver}
 * that returns the {@see PivotMetaSerializer} for the related type — so the linkage
 * identifiers carry their pivot meta. Every other relationship keeps the inner
 * serializer's callable untouched.
 *
 * The pivot map covers the WHOLE association (the relationship endpoint renders all
 * linkage off the parent, not a windowed page), so the provider supplies a full map
 * for this render.
 */
final class PivotParentSerializer implements SerializerInterface, IncludeControlsInterface
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

    public function getType(mixed $object): string
    {
        return $this->inner->getType($object);
    }

    public function getId(mixed $object): string
    {
        return $this->inner->getId($object);
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->inner->getMeta($object, $request);
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return $this->inner->getLinks($object, $request);
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->inner->getAttributes($object, $request);
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return $this->inner->getDefaultIncludedRelationships($object);
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        $relationships = $this->inner->getRelationships($object, $request);

        $substituting = new PivotSubstitutingResolver(
            $this->resolver,
            $this->relation->relatedTypes()[0] ?? '',
            $this->pivotSerializer,
        );

        $relationships[$this->relationshipName] = fn(mixed $model, JsonApiRequestInterface $req, string $name): AbstractRelationship => $this->relation->buildRelationship($model, $req, $substituting);

        return $relationships;
    }

    public function getNonIncludableRelationships(JsonApiRequestInterface $request, mixed $object): array
    {
        return $this->inner instanceof IncludeControlsInterface
            ? $this->inner->getNonIncludableRelationships($request, $object)
            : [];
    }

    public function maxIncludeDepth(): ?int
    {
        return $this->inner instanceof IncludeControlsInterface
            ? $this->inner->maxIncludeDepth()
            : null;
    }

    public function getAllowedIncludePaths(): ?array
    {
        return $this->inner instanceof IncludeControlsInterface
            ? $this->inner->getAllowedIncludePaths()
            : null;
    }
}
