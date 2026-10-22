<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Resource\ProvidesOutputTimezoneInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Serializer\RelationshipCountInterface;
use haddowg\JsonApi\Serializer\RelationshipLinkageInterface;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApi\Serializer\RelationshipPaginationInterface;
use haddowg\JsonApi\Serializer\ResourceLinkContributorInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * A {@see SerializerResolverInterface} decorator that substitutes one type's
 * serializer for a {@see PivotMetaSerializer}, delegating every other resolution to
 * the inner resolver (the {@see \haddowg\JsonApi\Server\Server}).
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
 * It passes the inner resolver's output timezone through too, so anything that reads
 * the server default off this resolver sees the same zone the Server carries.
 */
final class PivotSubstitutingResolver implements SerializerResolverInterface, ProvidesOutputTimezoneInterface
{
    public function __construct(
        private readonly SerializerResolverInterface $inner,
        private readonly string $type,
        private readonly PivotMetaSerializer $pivotSerializer,
    ) {}

    public function serializerFor(string $type): SerializerInterface
    {
        return $type === $this->type ? $this->pivotSerializer : $this->inner->serializerFor($type);
    }

    public function hasSerializerFor(string $type): bool
    {
        return $type === $this->type ? true : $this->inner->hasSerializerFor($type);
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
}
