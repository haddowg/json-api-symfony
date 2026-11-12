<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionLinks;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresAlwaysCounted;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresClientIdCollisions;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresDefaultSort;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresDeprecation;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresHydratorCardinality;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresIncludeSafeguards;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresInclusionUnsupported;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresNamedOperationExtensions;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationExamples;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationExtensions;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationScopes;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresRelatedOnly;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresResourceClass;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresResourceLinks;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresSingularName;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresSortExample;
use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponseInterface;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponses;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Link\ActionLink;
use haddowg\JsonApi\Resource\Link\DeclaredLink;
use haddowg\JsonApi\Resource\Sort\SortDirective;
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
 * It implements core's opt-in {@see DeclaresOperationExtensions} and
 * {@see DeclaresNamedOperationExtensions} to carry the vendor extensions declared on
 * `#[AsJsonApiResource]` (docs/openapi.md): the CRUD sets keyed by Operation case name
 * go through the first, the sets keyed by core operation name through the second. A type
 * that declares none returns empty maps and the projector emits nothing.
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
 * And core's opt-in {@see DeclaresResourceLinks} and {@see DeclaresActionLinks}: the
 * resource's declared links and action links, empty for a standalone type.
 *
 * And core's opt-in {@see DeclaresOperationScopes}, the OAuth scope overrides declared on
 * `#[AsJsonApiResource(operationScopes:)]`, and {@see DeclaresResourceClass}, the class
 * the type is declared by, so a scope resolver can read the app's own attributes off it.
 *
 * And core's opt-in {@see DeclaresDeprecation}: whether `#[AsJsonApiResource(deprecation:)]`
 * deprecates the whole type, the date its `sunset:` names, and the operations its
 * `deprecatedOperations:` lists.
 *
 * And core's opt-in {@see DeclaresRelatedOnly}: whether `#[AsJsonApiResource(relatedOnly:)]`
 * says the type serves no endpoint of its own.
 *
 * And core's opt-in {@see DeclaresDefaultSort} and {@see DeclaresSortExample}: the
 * resource's `defaultSort()` and `sortExample()`, which the projector turns into the
 * `sort` parameter's example. Both are empty for a standalone type.
 *
 * And the facts core's error coverage reads about the type: the include safeguards its
 * serializer applies as a document's root, that no operation refuses `include`, that a
 * taken client id is a `409`, and that a hand-written hydrator never reads linkage.
 */
final readonly class TypeMetadata implements TypeMetadataInterface, DeclaresOperationExtensions, DeclaresNamedOperationExtensions, DeclaresSingularName, DeclaresOperationExamples, DeclaresAlwaysCounted, DeclaresResourceLinks, DeclaresActionLinks, DeclaresOperationScopes, DeclaresResourceClass, DeclaresDeprecation, DeclaresRelatedOnly, DeclaresDefaultSort, DeclaresSortExample, DeclaresIncludeSafeguards, DeclaresInclusionUnsupported, DeclaresClientIdCollisions, DeclaresHydratorCardinality
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
     * @param array<string, list<string>>     $operationScopes     the declared OAuth scope overrides, keyed by core's operation name
     * @param class-string|null               $resourceClass       the resource or standalone serializer class the type is declared by
     * @param list<ActionLink>                $actionLinks         the resource's built action links, in declaration order
     * @param array<string, array<string, mixed>> $namedOperationExtensions vendor extensions keyed by core's operation name
     * @param bool                            $deprecated          whether the whole type is declared deprecated
     * @param ?\DateTimeInterface             $sunset              the declared sunset date, or null
     * @param list<string>                    $deprecatedOperations the operations deprecated on their own, by core's operation name
     * @param bool                            $relatedOnly         whether the type serves no endpoint of its own
     * @param list<SortDirective>             $defaultSort         the resource's default order, most significant first
     * @param list<string>|null               $sortExample         the resource's declared `sort` example tokens, or null to derive one
     * @param list<string>|null               $allowedIncludePaths  the root include allow-list the type's serializer declares, or null for none
     * @param list<string>                    $excludedIncludePaths the root include paths the type's serializer refuses
     * @param ?int                            $maxIncludeDepth      the type's own maximum include depth, or null for the server's
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
        private array $operationScopes = [],
        private ?string $resourceClass = null,
        private array $actionLinks = [],
        private array $namedOperationExtensions = [],
        private bool $deprecated = false,
        private ?\DateTimeInterface $sunset = null,
        private array $deprecatedOperations = [],
        private bool $relatedOnly = false,
        private array $defaultSort = [],
        private ?array $sortExample = null,
        private ?array $allowedIncludePaths = null,
        private array $excludedIncludePaths = [],
        private ?int $maxIncludeDepth = null,
    ) {}

    public function isRelatedOnly(): bool
    {
        return $this->relatedOnly;
    }

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

    public function namedOperationExtensions(): array
    {
        return $this->namedOperationExtensions;
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

    public function operationScopes(): array
    {
        return $this->operationScopes;
    }

    public function resourceClass(): ?string
    {
        return $this->resourceClass;
    }

    public function actionLinks(): array
    {
        return $this->actionLinks;
    }

    public function isDeprecated(): bool
    {
        return $this->deprecated;
    }

    public function sunset(): ?\DateTimeInterface
    {
        return $this->sunset;
    }

    public function deprecatedOperations(): array
    {
        return $this->deprecatedOperations;
    }

    public function defaultSort(): array
    {
        return $this->defaultSort;
    }

    public function sortExample(): ?array
    {
        return $this->sortExample;
    }

    public function allowedIncludePaths(): ?array
    {
        return $this->allowedIncludePaths;
    }

    public function excludedIncludePaths(): array
    {
        return $this->excludedIncludePaths;
    }

    public function maxIncludeDepth(): ?int
    {
        return $this->maxIncludeDepth;
    }

    /**
     * No handler the bundle ships refuses `include` outright.
     */
    public function rejectsInclude(OperationKey $operation): bool
    {
        return false;
    }

    /**
     * Both reference persisters refuse a taken client id, and a custom one may.
     */
    public function detectsClientIdCollisions(): bool
    {
        return true;
    }

    /**
     * The handler takes `relationships` out of a body before the hydrator sees it and
     * writes linkage through the persister, so a hand-written hydrator never checks its
     * cardinality.
     */
    public function checksRelationshipCardinality(): bool
    {
        return false;
    }
}
