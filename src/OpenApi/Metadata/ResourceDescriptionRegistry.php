<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;

/**
 * The type-keyed registry of declarative OpenAPI **description overrides** declared
 * via `#[AsJsonApiResource(description: …, operationDescriptions: …)]` (bundle ADR
 * 0092). Built from a plain scalar `type → {description, operations}` map the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceDescriptionPass}
 * assembles from each resource's tag attributes (the map flows through the container
 * as scalars; the per-operation map is carried as one JSON string, like the response
 * headers, because a nested array is not a dumpable flat tag attribute).
 *
 * Descriptions are **type-keyed and server-independent** (like the security and
 * relations registries): a type that joins several servers carries one set. The
 * {@see MetadataSource} layers this registry *beneath* a resource's own
 * {@see \haddowg\JsonApi\Resource\AbstractResource::getDescription()} /
 * {@see \haddowg\JsonApi\Resource\AbstractResource::describeOperation()} method hooks
 * (the method wins), and core's projector supplies the generated default when both
 * resolve to `null`.
 */
final class ResourceDescriptionRegistry
{
    /** @var array<string, string> */
    private array $descriptionByType = [];

    /** @var array<string, array<string, string>> */
    private array $operationDescriptionsByType = [];

    /**
     * @param array<string, array{description?: string|null, operations?: array<string, string>}> $descriptions
     */
    public function __construct(array $descriptions = [])
    {
        foreach ($descriptions as $type => $set) {
            $description = $set['description'] ?? null;
            if (\is_string($description) && $description !== '') {
                $this->descriptionByType[$type] = $description;
            }

            $operations = $set['operations'] ?? [];
            $byOperation = [];
            foreach ($operations as $operation => $value) {
                if (\is_string($value) && $value !== '') {
                    $byOperation[$operation] = $value;
                }
            }

            if ($byOperation !== []) {
                $this->operationDescriptionsByType[$type] = $byOperation;
            }
        }
    }

    /**
     * The declared resource-object description for `$type`, or `null` when none was
     * declared on the attribute (the resource method hook / the generated default then
     * applies).
     */
    public function descriptionFor(string $type): ?string
    {
        return $this->descriptionByType[$type] ?? null;
    }

    /**
     * The declared description override for one of `$type`'s CRUD operations, or
     * `null` when none was declared on the attribute for that operation.
     */
    public function operationDescriptionFor(string $type, OperationType $operation): ?string
    {
        return $this->operationDescriptionsByType[$type][$operation->value] ?? null;
    }
}
