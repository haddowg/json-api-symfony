<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Resource\DeclaresRelatedOnlyTypesInterface;
use haddowg\JsonApi\Resource\DeclaresSingletonTypesInterface;
use haddowg\JsonApi\Resource\ProvidesOutputTimezoneInterface;
use haddowg\JsonApi\Resource\ProvidesRelationAuthorizerInterface;
use haddowg\JsonApi\Resource\ProvidesStorageCastInterface;
use haddowg\JsonApi\Resource\RejectsReadOnlyAttributesInterface;
use haddowg\JsonApi\Resource\RelationAuthorizerInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Resource\SingletonContext;
use haddowg\JsonApi\Resource\StorageCastInterface;
use haddowg\JsonApi\Serializer\RelationshipCountInterface;
use haddowg\JsonApi\Serializer\RelationshipLinkageInterface;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApi\Serializer\RelationshipPaginationInterface;
use haddowg\JsonApi\Serializer\ResourceLinkContributorInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * A {@see SerializerResolverInterface} decorator that substitutes one type's
 * serializer for a {@see PivotMetaSerializer}, delegating every other resolution to
 * the inner resolver (the {@see \haddowg\JsonApi\Server\Server}). It can substitute
 * further types too, which is how a relation reached through a nested include renders
 * its related resources through serializers that rebind their own pivot relations.
 *
 * Used to render pivot meta on the **relationship-linkage** endpoint
 * (`GET /{type}/{id}/relationships/{rel}`): that endpoint renders the parent's
 * relationship through the parent serializer, which resolves the related type's
 * serializer through a resolver to build the linkage. Passing this resolver to the
 * relation's {@see \haddowg\JsonApi\Resource\Field\RelationInterface::buildRelationship()}
 * binds the {@see PivotMetaSerializer} to the linkage, so each identifier carries
 * its `meta.pivot` — riding core's existing identifier-meta render path with no core
 * change.
 *
 * It passes the inner resolver's output timezone, relation authorizer, read-only
 * attribute rejection, related-only types and singleton types through, so the linkage it
 * builds renders in the server's zone, is withheld from a caller the relation's read
 * expression refuses, and a resource it resolves keeps its server's write rules and links.
 */
final class PivotSubstitutingResolver implements SerializerResolverInterface, ProvidesOutputTimezoneInterface, ProvidesRelationAuthorizerInterface, ProvidesStorageCastInterface, RejectsReadOnlyAttributesInterface, DeclaresRelatedOnlyTypesInterface, DeclaresSingletonTypesInterface
{
    /**
     * @param array<string, SerializerInterface> $substitutes the serializers of further types to substitute, keyed by type
     */
    public function __construct(
        private readonly SerializerResolverInterface $inner,
        private readonly string $type,
        private readonly SerializerInterface $pivotSerializer,
        private readonly array $substitutes = [],
    ) {}

    public function serializerFor(string $type): SerializerInterface
    {
        return $type === $this->type ? $this->pivotSerializer : $this->substitutes[$type] ?? $this->inner->serializerFor($type);
    }

    public function hasSerializerFor(string $type): bool
    {
        return $type === $this->type || isset($this->substitutes[$type]) || $this->inner->hasSerializerFor($type);
    }

    public function relationshipLoadState(): ?RelationshipLoadStateInterface
    {
        return $this->inner->relationshipLoadState();
    }

    public function relationshipCount(): ?RelationshipCountInterface
    {
        return $this->inner->relationshipCount();
    }

    public function relationshipPagination(): ?RelationshipPaginationInterface
    {
        return $this->inner->relationshipPagination();
    }

    public function relationshipLinkage(): ?RelationshipLinkageInterface
    {
        return $this->inner->relationshipLinkage();
    }

    public function resourceLinkContributor(): ?ResourceLinkContributorInterface
    {
        return $this->inner->resourceLinkContributor();
    }

    public function outputTimezone(): ?string
    {
        return $this->inner instanceof ProvidesOutputTimezoneInterface ? $this->inner->outputTimezone() : null;
    }

    public function relationAuthorizer(): ?RelationAuthorizerInterface
    {
        return $this->inner instanceof ProvidesRelationAuthorizerInterface ? $this->inner->relationAuthorizer() : null;
    }

    public function storageCast(): ?StorageCastInterface
    {
        return $this->inner instanceof ProvidesStorageCastInterface ? $this->inner->storageCast() : null;
    }

    public function rejectsReadOnlyAttributes(): bool
    {
        return $this->inner instanceof RejectsReadOnlyAttributesInterface && $this->inner->rejectsReadOnlyAttributes();
    }

    public function isRelatedOnly(string $type): bool
    {
        return $this->inner instanceof DeclaresRelatedOnlyTypesInterface && $this->inner->isRelatedOnly($type);
    }

    public function isSingleton(string $type): bool
    {
        return $this->inner instanceof DeclaresSingletonTypesInterface && $this->inner->isSingleton($type);
    }

    public function resolveSingletonId(string $type, SingletonContext $context): ?string
    {
        return $this->inner instanceof DeclaresSingletonTypesInterface ? $this->inner->resolveSingletonId($type, $context) : null;
    }
}
