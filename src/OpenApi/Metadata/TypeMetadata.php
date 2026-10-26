<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresAlwaysCounted;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationExamples;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationExtensions;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresResourceLinks;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresSingularName;
use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponseInterface;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponses;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Link\DeclaredLink;
use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * One JSON:API type's OpenAPI metadata, assembled by the {@see MetadataSource} from
 * the live registry (its route descriptor, resource/relations, paginator, security
 * registry, action registry and resolved tags) into a plain immutable carrier the
 * core projector reads.
 *
 * It tolerates a **standalone** type (no resource): `hasFields()` is then `false`,
 * `fields()`/`relations()`/`filters()`/`sorts()` are empty, and the projector emits
 * a permissive resource-object schema. Everything else — uriType, operations, tags,
 * the page schema — is sourced independently of the field inventory.
 *
 * It implements core's opt-in {@see DeclaresOperationExtensions} to carry the vendor
 * extensions declared on `#[AsJsonApiResource]` (bundle ADR 0127); a type that
 * declares none returns empty maps and the projector emits nothing.
 *
 * It also implements core's opt-in {@see DeclaresSingularName}: the noun the generated
 * prose uses for one resource, or `null` to let core inflect it from the type name.
 *
 * And core's opt-in {@see DeclaresOperationExamples}: the example overrides declared on
 * `#[AsJsonApiResource(operationExamples:)]`, empty when the type declares none.
 *
 * And core's opt-in {@see DeclaresAlwaysCounted}: whether the primary collection's
 * paginator counts on every request.
 *
 * And core's opt-in {@see DeclaresResourceLinks}: the resource's declared links, empty
 * for a standalone type.
 */
final readonly class TypeMetadata implements TypeMetadataInterface, DeclaresOperationExtensions, DeclaresSingularName, DeclaresOperationExamples, DeclaresAlwaysCounted, DeclaresResourceLinks
{
    /**
     * @param list<FieldInterface>            $fields
     * @param list<RelationMetadataInterface> $relations
     * @param list<OperationType>             $operations
     * @param list<OperationType>             $securedOperations
     * @param list<OperationType>             $publicOperations
     * @param list<FilterInterface>           $filters
     * @param list<SortInterface>             $sorts
     * @param list<ActionMetadataInterface>   $actions
     * @param list<string>                    $tags
     * @param array<string, ?string>          $operationDescriptions per-CRUD-operation description overrides, keyed by {@see OperationType::value}; a missing key (and a null value) means "no override" — the projector emits the generated default
     * @param array<string, non-empty-list<OperationResponseInterface>> $responseOverrides per-operation success-response overrides, keyed by {@see OperationType::value}; a missing key means "no override" — {@see responsesFor()} falls back to {@see OperationResponses::defaultFor()}
     * @param list<string>                    $includablePaths
     * @param array<string, mixed>            $extensions          vendor extensions for every operation of the type
     * @param array<string, array<string, mixed>> $operationExtensions per-CRUD-operation vendor extensions, keyed by {@see OperationType::value}
     * @param ?string                         $singularName        the declared singular noun for generated prose (null = inflected by core)
     * @param array<string, OperationExample|false> $operationExamples the declared operation example overrides, keyed by core's operation name
     * @param bool                            $alwaysCounts        whether the resolved paginator counts on every request
     * @param list<DeclaredLink>              $declaredLinks       the resource's built declared links, in declaration order
     */
    public function __construct(
        private string $type,
        private string $uriType,
        private bool $hasFields,
        private array $fields,
        private array $relations,
        private array $operations,
        private array $securedOperations,
        private array $publicOperations,
        private bool $allowsClientId,
        private bool $requiresClientId,
        private ?string $idPattern,
        private ?Schema $pageSchema,
        private bool $countable,
        private array $filters,
        private array $sorts,
        private array $actions,
        private array $tags,
        private ?string $description,
        private array $operationDescriptions,
        private array $responseOverrides,
        private array $includablePaths,
        private array $extensions = [],
        private array $operationExtensions = [],
        private ?string $singularName = null,
        private array $operationExamples = [],
        private bool $alwaysCounts = false,
        private array $declaredLinks = [],
    ) {}

    public function type(): string
    {
        return $this->type;
    }

    public function uriType(): string
    {
        return $this->uriType;
    }

    public function hasFields(): bool
    {
        return $this->hasFields;
    }

    public function fields(): array
    {
        return $this->fields;
    }

    public function relations(): array
    {
        return $this->relations;
    }

    public function operations(): array
    {
        return $this->operations;
    }

    public function securedOperations(): array
    {
        return $this->securedOperations;
    }

    public function publicOperations(): array
    {
        return $this->publicOperations;
    }

    public function allowsClientId(): bool
    {
        return $this->allowsClientId;
    }

    public function requiresClientId(): bool
    {
        return $this->requiresClientId;
    }

    public function idPattern(): ?string
    {
        return $this->idPattern;
    }

    public function pageSchema(): ?Schema
    {
        return $this->pageSchema;
    }

    public function isCountable(): bool
    {
        return $this->countable;
    }

    public function filters(): array
    {
        return $this->filters;
    }

    public function sorts(): array
    {
        return $this->sorts;
    }

    public function actions(): array
    {
        return $this->actions;
    }

    public function tags(): array
    {
        return $this->tags;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function operationDescription(OperationType $op): ?string
    {
        return $this->operationDescriptions[$op->value] ?? null;
    }

    public function responsesFor(OperationType $operation): array
    {
        return $this->responseOverrides[$operation->value] ?? OperationResponses::defaultFor($operation);
    }

    public function includablePaths(): array
    {
        return $this->includablePaths;
    }

    public function extensions(): array
    {
        return $this->extensions;
    }

    public function operationExtensions(OperationType $operation): array
    {
        return $this->operationExtensions[$operation->value] ?? [];
    }

    public function singularName(): ?string
    {
        return $this->singularName;
    }

    public function operationExamples(): array
    {
        return $this->operationExamples;
    }

    public function alwaysCounts(): bool
    {
        return $this->alwaysCounts;
    }

    public function declaredLinks(): array
    {
        return $this->declaredLinks;
    }
}
