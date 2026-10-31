<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\DeclaresAlwaysCounted;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresIdentifiedMembers;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresPolymorphicRelation;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresRelationExampleId;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresRelationUriFieldName;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresWriteOnlyRelation;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Resource\Field\AbstractRelationValue;
use haddowg\JsonApi\Resource\Field\ExemplifiedRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataProvider\PivotFields;
use haddowg\JsonApiBundle\DataProvider\Polymorphism;
use haddowg\JsonApiBundle\DataProvider\RelatedReadRouter;

/**
 * Adapts a core {@see RelationInterface} (a resource's relation field, or a
 * standalone-registered relation) to the OpenAPI {@see RelationMetadataInterface}
 * the projector consumes.
 *
 * Most accessors delegate straight to the relation's own already-resolved facts
 * (endpoint exposure, mutation flags, includability, filters/sorts). The two
 * derived facts — the related-collection page {@see Schema} and the related-
 * endpoint includable paths — are resolved by the {@see MetadataSource} (which has
 * the {@see \haddowg\JsonApi\Server\Server} fallback chain + the relation graph) and
 * passed in, as is whether that paginator counts on every request.
 */
final readonly class RelationMetadata implements RelationMetadataInterface, DeclaresWriteOnlyRelation, DeclaresRelationUriFieldName, DeclaresAlwaysCounted, DeclaresRelationExampleId, DeclaresPolymorphicRelation, DeclaresIdentifiedMembers
{
    /**
     * @param list<string> $relatedIncludablePaths the related type's includable paths (resolved by the source)
     */
    public function __construct(
        private RelationInterface $relation,
        private ?Schema $pageSchema,
        private array $relatedIncludablePaths,
        private bool $alwaysCounts = false,
    ) {}

    public function name(): string
    {
        return $this->relation->name();
    }

    public function uriFieldName(): string
    {
        return $this->relation instanceof AbstractRelationValue ? $this->relation->uriFieldName() : $this->relation->name();
    }

    public function relatedTypes(): array
    {
        return $this->relation->relatedTypes();
    }

    /**
     * A `MorphTo` or `MorphToMany` over one type is projected as a union, as it is
     * served.
     */
    public function isPolymorphic(): bool
    {
        return Polymorphism::of($this->relation);
    }

    public function storesIdentifiers(): bool
    {
        return RelatedReadRouter::identifiesMembers($this->relation);
    }

    public function isToMany(): bool
    {
        return $this->relation->isToMany();
    }

    public function description(): ?string
    {
        return $this->relation->getDescription();
    }

    /**
     * Only an unconditional `writeOnly()`: a predicate-gated relation stays in the
     * superset document, as a predicate-gated attribute does.
     */
    public function isWriteOnly(): bool
    {
        return $this->relation->isWriteOnly();
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

    public function securityRead(): string|bool|null
    {
        return $this->relation->securityRead();
    }

    public function securityMutate(): string|bool|null
    {
        return $this->relation->securityMutate();
    }

    public function pageSchema(): ?Schema
    {
        return $this->pageSchema;
    }

    public function alwaysCounts(): bool
    {
        return $this->alwaysCounts;
    }

    public function exampleId(): ?string
    {
        return $this->relation instanceof ExemplifiedRelationInterface ? $this->relation->exampleId() : null;
    }

    public function filters(): array
    {
        return $this->relation->allFilters();
    }

    public function sorts(): array
    {
        // A belongsToMany's pivot fields contribute an auto-derived `?sort=<field>`
        // vocabulary the runtime honours on this relation's related/relationship
        // endpoints (RelationCriteriaFactory merges PivotFields::sortsFor when the
        // pivot-aware provider sets includePivotFields). Surface them here so the
        // OpenAPI `sort` enum advertises them too — mirroring how the pivot FILTERS,
        // being author-declared in filters(), are already advertised. Empty for a
        // non-pivot relation (docs/openapi.md).
        return [...$this->relation->sorts(), ...PivotFields::sortsFor($this->relation)];
    }

    public function relatedIncludablePaths(): array
    {
        return $this->relatedIncludablePaths;
    }

    public function pivotFields(): array
    {
        // A belongsToMany relation's declared pivot fields (real FieldInterface
        // definitions) — the same source PivotFields::sortsFor() derives the pivot
        // sort vocabulary from. Empty for any non-pivot relation, so the projector
        // types meta.pivot only where the runtime actually renders it.
        return PivotFields::declaredFor($this->relation);
    }
}
