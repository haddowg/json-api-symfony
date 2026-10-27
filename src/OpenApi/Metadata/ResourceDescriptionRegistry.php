<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApiBundle\Server\ServerProvider;

/**
 * The registry of declarative OpenAPI **description overrides** declared
 * via `#[AsJsonApiResource(description: …, operationDescriptions: …)]` (bundle ADR
 * 0092). Built from a plain scalar `type → {description, operations}` map the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceDescriptionPass}
 * assembles from each resource's tag attributes (the map flows through the container
 * as scalars; the per-operation map is carried as one JSON string, like the response
 * headers, because a nested array is not a dumpable flat tag attribute).
 *
 * Descriptions are keyed by server and type (bundle ADR 0144): each server holds only
 * what its own resource class or standalone serializer declared, a class on several
 * servers is held under each, and a server whose class declared nothing gets the
 * generated default rather than another server's values. The
 * {@see MetadataSource} layers this registry *beneath* a resource's own
 * {@see \haddowg\JsonApi\Resource\AbstractResource::getDescription()} /
 * {@see \haddowg\JsonApi\Resource\AbstractResource::describeOperation()} method hooks
 * (the method wins), and core's projector supplies the generated default when both
 * resolve to `null`.
 *
 * It also carries the declared singular noun (`singular:`), which the
 * {@see MetadataSource} layers beneath the resource's
 * {@see \haddowg\JsonApi\Resource\AbstractResource::getSingularName()} hook the same way.
 *
 * And it carries the declared OpenAPI vendor extensions
 * (`#[AsJsonApiResource(extensions: …, operationExtensions: …)]`, bundle ADR 0127):
 * the type-wide set and the per-CRUD-operation sets, which core merges onto the
 * type's operations. Core has no resource method hook for extensions, so the
 * attribute is their only declaration site.
 *
 * It carries the declared operation example overrides
 * (`#[AsJsonApiResource(operationExamples: …)]`, core ADR 0176), held as scalars and
 * rebuilt into core's {@see OperationExample} on read.
 *
 * Last, it carries the declared OAuth scope overrides
 * (`#[AsJsonApiResource(operationScopes: …)]`, core ADR 0181).
 */
final class ResourceDescriptionRegistry
{
    /** @var array<string, array<string, string>> */
    private array $descriptionByType = [];

    /** @var array<string, array<string, array<string, string>>> */
    private array $operationDescriptionsByType = [];

    /** @var array<string, array<string, string>> */
    private array $singularByType = [];

    /** @var array<string, array<string, array{type: array<string, mixed>, operations: array<string, array<string, mixed>>}>> */
    private array $extensionsByType = [];

    /** @var array<string, array<string, array<string, array{request: mixed, responses: array<int|string, mixed>}|false>>> */
    private array $examplesByType = [];

    /** @var array<string, array<string, array<string, list<string>>>> */
    private array $scopesByType = [];

    /**
     * @param array<string, array{description?: string|null, operations?: array<string, string>, singular?: string|null}> $descriptions the default server's
     * @param array<string, array{type?: array<string, mixed>, operations?: array<string, array<string, mixed>>}>       $extensions   the default server's
     * @param array<string, array{
     *     descriptions?: array<string, array{description?: string|null, operations?: array<string, string>, singular?: string|null}>,
     *     extensions?: array<string, array{type?: array<string, mixed>, operations?: array<string, array<string, mixed>>}>,
     *     examples?: array<string, array<string, array{request: mixed, responses: array<int|string, mixed>}|false>>,
     *     scopes?: array<string, array<string, list<string>>>,
     * }> $servers every other server's, keyed by server name
     * @param array<string, array<string, array{request: mixed, responses: array<int|string, mixed>}|false>> $examples the default server's operation example overrides, by type then operation name
     * @param array<string, array<string, list<string>>> $scopes the default server's OAuth scope overrides, by type then operation name
     */
    public function __construct(array $descriptions = [], array $extensions = [], array $servers = [], array $examples = [], array $scopes = [])
    {
        $default = $servers[ServerProvider::DEFAULT_SERVER] ?? [];
        $servers[ServerProvider::DEFAULT_SERVER] = [
            'descriptions' => ($default['descriptions'] ?? []) + $descriptions,
            'extensions' => ($default['extensions'] ?? []) + $extensions,
            'examples' => ($default['examples'] ?? []) + $examples,
            'scopes' => ($default['scopes'] ?? []) + $scopes,
        ];

        foreach ($servers as $server => $declared) {
            foreach ($declared['scopes'] ?? [] as $type => $set) {
                $this->scopesByType[$server][$type] = $set;
            }

            foreach ($declared['examples'] ?? [] as $type => $set) {
                $this->examplesByType[$server][$type] = $set;
            }

            foreach ($declared['extensions'] ?? [] as $type => $set) {
                $this->extensionsByType[$server][$type] = [
                    'type' => $set['type'] ?? [],
                    'operations' => $set['operations'] ?? [],
                ];
            }

            foreach ($declared['descriptions'] ?? [] as $type => $set) {
                $description = $set['description'] ?? null;
                if (\is_string($description) && $description !== '') {
                    $this->descriptionByType[$server][$type] = $description;
                }

                $singular = $set['singular'] ?? null;
                if (\is_string($singular) && \trim($singular) !== '') {
                    $this->singularByType[$server][$type] = \trim($singular);
                }

                $byOperation = [];
                foreach ($set['operations'] ?? [] as $operation => $value) {
                    if (\is_string($value) && $value !== '') {
                        $byOperation[$operation] = $value;
                    }
                }

                if ($byOperation !== []) {
                    $this->operationDescriptionsByType[$server][$type] = $byOperation;
                }
            }
        }
    }

    /**
     * The declared resource-object description for `$type` on `$server`, or `null` when
     * none was declared on the attribute (the resource method hook / the generated
     * default then applies).
     */
    public function descriptionFor(string $type, string $server = ServerProvider::DEFAULT_SERVER): ?string
    {
        return $this->descriptionByType[$server][$type] ?? null;
    }

    /**
     * The declared description override for one of `$type`'s CRUD operations on
     * `$server`, or `null` when none was declared on the attribute for that operation.
     */
    public function operationDescriptionFor(string $type, OperationType $operation, string $server = ServerProvider::DEFAULT_SERVER): ?string
    {
        return $this->operationDescriptionsByType[$server][$type][$operation->value] ?? null;
    }

    /**
     * The declared singular noun for `$type` on `$server`, or `null` when the attribute
     * declares none (the resource method hook / the inflected singular then applies).
     */
    public function singularFor(string $type, string $server = ServerProvider::DEFAULT_SERVER): ?string
    {
        return $this->singularByType[$server][$type] ?? null;
    }

    /**
     * The vendor extensions declared for every operation of `$type` on `$server`; empty
     * when none.
     *
     * @return array<string, mixed>
     */
    public function extensionsFor(string $type, string $server = ServerProvider::DEFAULT_SERVER): array
    {
        return $this->extensionsByType[$server][$type]['type'] ?? [];
    }

    /**
     * The vendor extensions declared for one of `$type`'s CRUD operations on `$server`,
     * before the merge over {@see extensionsFor()} core applies; empty when none.
     *
     * @return array<string, mixed>
     */
    public function operationExtensionsFor(string $type, OperationType $operation, string $server = ServerProvider::DEFAULT_SERVER): array
    {
        return $this->extensionsByType[$server][$type]['operations'][$operation->value] ?? [];
    }

    /**
     * The operation example overrides declared for `$type` on `$server`, keyed by core's
     * operation name; empty when none.
     *
     * @return array<string, OperationExample|false>
     */
    public function operationExamplesFor(string $type, string $server = ServerProvider::DEFAULT_SERVER): array
    {
        return \array_map(OperationExampleCodec::toExample(...), $this->examplesByType[$server][$type] ?? []);
    }

    /**
     * The OAuth scope overrides declared for `$type` on `$server`, keyed by core's
     * operation name; empty when none.
     *
     * @return array<string, list<string>>
     */
    public function operationScopesFor(string $type, string $server = ServerProvider::DEFAULT_SERVER): array
    {
        return $this->scopesByType[$server][$type] ?? [];
    }
}
