<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\PaginatorKind;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataProvider\PivotFields;

/**
 * Adapts a core {@see RelationInterface} (a resource's relation field, or a
 * standalone-registered relation) to the OpenAPI {@see RelationMetadataInterface}
 * the projector consumes.
 *
 * Most accessors delegate straight to the relation's own already-resolved facts
 * (endpoint exposure, mutation flags, includability, filters/sorts). The two
 * derived facts — the related-collection {@see PaginatorKind} and the related-
 * endpoint includable paths — are resolved by the {@see MetadataSource} (which has
 * the {@see \haddowg\JsonApi\Server\Server} fallback chain + the relation graph) and
 * passed in.
 */
final readonly class RelationMetadata implements RelationMetadataInterface
{
    /**
     * @param list<string> $relatedIncludablePaths the related type's includable paths (resolved by the source)
     */
    public function __construct(
        private RelationInterface $relation,
        private PaginatorKind $paginatorKind,
        private array $relatedIncludablePaths,
    ) {}

    public function name(): string
    {
        return $this->relation->name();
    }

    public function relatedTypes(): array
    {
        return $this->relation->relatedTypes();
    }

    public function isToMany(): bool
    {
        return $this->relation->isToMany();
    }

    public function description(): ?string
    {
        return $this->relation->getDescription();
    }

    public function isIncludable(): bool
    {
        return $this->relation->isIncludable();
    }

    public function exposesRelatedEndpoint(): bool
    {
        return $this->relation->exposesRelatedEndpoint();
    }

    public function exposesRelationshipEndpoint(): bool
    {
        return $this->relation->exposesRelationshipEndpoint();
    }

    public function allowsReplace(): bool
    {
        return $this->relation->allowsReplace();
    }

    public function allowsAdd(): bool
    {
        return $this->relation->allowsAdd();
    }

    public function allowsRemove(): bool
    {
        return $this->relation->allowsRemove();
    }

    public function isCountable(): bool
    {
        return $this->relation->isCountable();
    }

    public function paginatorKind(): PaginatorKind
    {
        return $this->paginatorKind;
    }

    public function filters(): array
    {
        return $this->relation->filters();
    }

    public function sorts(): array
    {
        // A belongsToMany's pivot fields contribute an auto-derived `?sort=<field>`
        // vocabulary the runtime honours on this relation's related/relationship
        // endpoints (RelationCriteriaFactory merges PivotFields::sortsFor when the
        // pivot-aware provider sets includePivotFields). Surface them here so the
        // OpenAPI `sort` enum advertises them too — mirroring how the pivot FILTERS,
        // being author-declared in filters(), are already advertised. Empty for a
        // non-pivot relation (bundle ADR 0097).
        return [...$this->relation->sorts(), ...PivotFields::sortsFor($this->relation)];
    }

    public function relatedIncludablePaths(): array
    {
        return $this->relatedIncludablePaths;
    }
}
