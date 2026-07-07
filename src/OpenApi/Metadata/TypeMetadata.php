<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponseInterface;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponses;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\PaginatorKind;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
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
 * paginatorKind — is sourced independently of the field inventory.
 */
final readonly class TypeMetadata implements TypeMetadataInterface
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
        private PaginatorKind $paginatorKind,
        private bool $countable,
        private array $filters,
        private array $sorts,
        private array $actions,
        private array $tags,
        private ?string $description,
        private array $operationDescriptions,
        private array $responseOverrides,
        private array $includablePaths,
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

    public function paginatorKind(): PaginatorKind
    {
        return $this->paginatorKind;
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
}
