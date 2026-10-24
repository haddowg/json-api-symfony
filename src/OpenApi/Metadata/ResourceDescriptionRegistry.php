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
 *
 * It also carries the declared OpenAPI vendor extensions
 * (`#[AsJsonApiResource(extensions: …, operationExtensions: …)]`, bundle ADR 0127):
 * the type-wide set and the per-CRUD-operation sets, which core merges onto the
 * type's operations. Core has no resource method hook for extensions, so the
 * attribute is their only declaration site.
 */
final class ResourceDescriptionRegistry
{
    /** @var array<string, string> */
    private array $descriptionByType = [];

    /** @var array<string, array<string, string>> */
    private array $operationDescriptionsByType = [];

    /** @var array<string, array{type: array<string, mixed>, operations: array<string, array<string, mixed>>}> */
    private array $extensionsByType = [];

    /**
     * @param array<string, array{description?: string|null, operations?: array<string, string>}>                   $descriptions
     * @param array<string, array{type?: array<string, mixed>, operations?: array<string, array<string, mixed>>}> $extensions
     */
    public function __construct(array $descriptions = [], array $extensions = [])
    {
        foreach ($extensions as $type => $set) {
            $this->extensionsByType[$type] = [
                'type' => $set['type'] ?? [],
                'operations' => $set['operations'] ?? [],
            ];
        }

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

    /**
     * The vendor extensions declared for every operation of `$type`; empty when none.
     *
     * @return array<string, mixed>
     */
    public function extensionsFor(string $type): array
    {
        return $this->extensionsByType[$type]['type'] ?? [];
    }

    /**
     * The vendor extensions declared for one of `$type`'s CRUD operations, before the
     * merge over {@see extensionsFor()} core applies; empty when none.
     *
     * @return array<string, mixed>
     */
    public function operationExtensionsFor(string $type, OperationType $operation): array
    {
        return $this->extensionsByType[$type]['operations'][$operation->value] ?? [];
    }
}
