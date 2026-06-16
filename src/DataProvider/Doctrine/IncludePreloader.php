<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Serializer\IncludeControlsInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\Server\ServerProvider;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;
use ShipMonk\DoctrineEntityPreloader\EntityPreloader;

/**
 * Laravel-style batch eager-loading of the effective `?include` tree against
 * Doctrine, so an included relationship does not N+1: one batched query loads a
 * relation for every source entity at a level, then its targets seed the next
 * level (ADR 0035). No fetch-joins — each level is a separate
 * `WHERE id IN (…)`-style query via {@see EntityPreloader}.
 *
 * It reuses core's include decision
 * ({@see JsonApiRequestInterface::isIncludedRelationship()}) so it preloads exactly
 * the tree the serializer renders: an explicitly requested `?include`, or — when the
 * request sends none — the resource's
 * {@see \haddowg\JsonApi\Resource\AbstractResource::getDefaultIncludedRelationships()}
 * fallback. The relation's storage column drives the batch
 * ({@see RelationInterface::column()} `??` its name), so a `storedAs()` rename is
 * honoured.
 *
 * Preloading is a pure optimization: a relation the preloader cannot batch falls
 * back to a lazy load and the rendered document is identical. Skipped (lazy) cases:
 * a polymorphic relation (more than one related type — no single target class to
 * batch), a relation whose column is not a real Doctrine association (a
 * computed/`extractUsing` value, or an alias that is not the association name), a
 * target with a composite identifier (the preloader does not support it), and any
 * preloader limitation surfaced as an exception (caught per relation).
 *
 * It also honours the three include safeguards (bundle ADR 0037): it never batches a
 * relation that is non-includable ({@see RelationInterface::isIncludable()} `=== false`),
 * never descends past the effective max include depth (the root resource's
 * {@see IncludeControlsInterface::maxIncludeDepth()} override, else the server default
 * {@see Server::maxIncludeDepth()}), and never batches a path the root resource's
 * {@see IncludeControlsInterface::getAllowedIncludePaths()} excludes. A request that
 * violates any of these `400`s in core before the provider runs, so this is belt and
 * braces — the preloader simply does not try to batch a relation core would reject,
 * and its recursion is bounded by the same effective depth so a mutual default-include
 * cycle terminates here too.
 *
 * The {@see Server} (and so the per-type metadata) is resolved through the
 * {@see ServerProvider}'s default server — the single-server-optimized common case;
 * a type registered only on a non-default server resolves no resource here and
 * simply renders lazily.
 */
final class IncludePreloader
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TypeMetadataResolver $types,
        private readonly ServerProvider $servers,
        private readonly EntityPreloader $preloader,
    ) {}

    /**
     * Batch-loads the effective include tree rooted at the `$type` entities in
     * `$entities`, recursing one level per `.`-separated include segment. A no-op
     * when there are no entities, the type has no resource (a bare pair declares no
     * relations), or no relation at this level is included.
     *
     * The include safeguards (bundle ADR 0037) are resolved once against the root
     * resource — the effective depth cap and the allowed-include-paths whitelist —
     * and threaded unchanged through the recursion, so the whole nested tree obeys
     * the root's policy.
     *
     * @param iterable<object> $entities
     * @param ?int             $maxDepth      the effective include-depth cap resolved at the root (null = unlimited)
     * @param list<string>|null $allowedPaths the root resource's allowed-include-paths whitelist (null = unrestricted)
     */
    public function preload(
        iterable $entities,
        string $type,
        JsonApiRequestInterface $request,
        string $basePath = '',
        ?int $maxDepth = null,
        ?array $allowedPaths = null,
        bool $rootResolved = false,
    ): void {
        $entities = $this->materialize($entities);
        if ($entities === []) {
            return;
        }

        $server = $this->servers->get();
        $relations = $this->types->relationsFor($server, $type);
        if ($relations === []) {
            return;
        }

        // Resolve the root-scoped safeguards once: the effective depth cap (the
        // primary resource's maxIncludeDepth() override ?? the server default,
        // normalised so <=0 is unlimited) and its allowed-include-paths whitelist.
        // Both then ride the recursion unchanged — they are a property of the root.
        if (!$rootResolved) {
            $serializer = $server->serializerFor($type);
            $maxDepth = $this->effectiveMaxDepth($serializer, $server);
            $allowedPaths = $serializer instanceof IncludeControlsInterface
                ? $serializer->getAllowedIncludePaths()
                : null;
            $rootResolved = true;
        }

        // The default includes are per-type-uniform here: read off the first entity
        // as the representative (the resource's getDefaultIncludedRelationships is a
        // type-level declaration, not a per-object one in the reference resources).
        $resource = $this->types->resourceFor($server, $type);
        $defaults = $resource === null
            ? []
            : \array_flip($resource->getDefaultIncludedRelationships($entities[0]));

        foreach ($relations as $relation) {
            // Capability A: a non-includable relation can never be part of the
            // rendered tree (core 400s a request that names it, and excludes it from
            // the default cascade), so it is never batched.
            if (!$relation->isIncludable()) {
                continue;
            }

            if (!$request->isIncludedRelationship($basePath, $relation->name(), $defaults)) {
                continue;
            }

            $childPath = $basePath === '' ? $relation->name() : $basePath . '.' . $relation->name();

            // Capability B: this relation sits at depth = the segment count of its
            // path; stop the descent once it would exceed the effective cap (this is
            // also what halts a mutual default-include cycle here).
            if ($maxDepth !== null && (\substr_count($childPath, '.') + 1) > $maxDepth) {
                continue;
            }

            // Capability C: when the root restricts include paths, only batch a path
            // it permits.
            if ($allowedPaths !== null && !\in_array($childPath, $allowedPaths, true)) {
                continue;
            }

            $this->preloadRelation($entities, $type, $relation, $request, $basePath, $maxDepth, $allowedPaths);
        }
    }

    /**
     * Batch-loads a single included relation across `$entities`, then recurses into
     * the loaded targets for nested includes. Silently falls back to a lazy load on
     * any case the preloader cannot batch (see the class docblock).
     *
     * @param list<object>      $entities
     * @param ?int              $maxDepth     the effective include-depth cap (null = unlimited)
     * @param list<string>|null $allowedPaths the root resource's allowed-include-paths whitelist (null = unrestricted)
     */
    private function preloadRelation(
        array $entities,
        string $type,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
        string $basePath,
        ?int $maxDepth,
        ?array $allowedPaths,
    ): void {
        // A polymorphic relation has more than one related type, so there is no
        // single target entity class to batch against — render it lazily.
        $relatedTypes = $relation->relatedTypes();
        if (\count($relatedTypes) !== 1) {
            return;
        }

        $property = $relation->column() ?? $relation->name();

        // The source class's metadata: the entities at this level share an ancestor
        // (the preloader enforces it), so the first entity's class is representative.
        $sourceMetadata = $this->entityManager->getClassMetadata($entities[0]::class);

        // A column that is not a real association (a computed/extractUsing value, or
        // an alias that is not the association name) cannot be batched — lazy.
        if (!$sourceMetadata->hasAssociation($property)) {
            return;
        }

        // The preloader does not support a target with a composite identifier — lazy.
        if ($this->targetHasCompositeIdentifier($sourceMetadata, $property)) {
            return;
        }

        try {
            // One batched query loads `$property` for every source entity.
            $targets = $this->preloader->preload($entities, $property);
        } catch (\Throwable) {
            // Any preloader limitation (indexed/dirty collection, unsupported mapping,
            // …) degrades to a lazy load — preloading must never break a request.
            return;
        }

        $childPath = $basePath === '' ? $relation->name() : $basePath . '.' . $relation->name();
        // Thread the root-scoped safeguards into the next level unchanged: the cap
        // and whitelist are a property of the root resource, not of each hop.
        $this->preload($targets, $relatedTypes[0], $request, $childPath, $maxDepth, $allowedPaths, rootResolved: true);
    }

    /**
     * The effective include-depth cap for a root `$serializer` on `$server`: the
     * resource's own {@see IncludeControlsInterface::maxIncludeDepth()} override (when
     * it implements the capability) `??` the server default
     * {@see Server::maxIncludeDepth()}, normalised so a non-positive value means
     * unlimited (`null`). Mirrors the resolution the transformer performs, so the
     * preloader bounds its recursion by exactly the tree core would render.
     */
    private function effectiveMaxDepth(\haddowg\JsonApi\Serializer\SerializerInterface $serializer, Server $server): ?int
    {
        $depth = ($serializer instanceof IncludeControlsInterface ? $serializer->maxIncludeDepth() : null)
            ?? $server->maxIncludeDepth();

        return ($depth !== null && $depth <= 0) ? null : $depth;
    }

    /**
     * Whether the association `$property` on `$sourceMetadata` targets an entity with
     * a composite identifier — which {@see EntityPreloader} cannot batch.
     *
     * @param ClassMetadata<object> $sourceMetadata
     */
    private function targetHasCompositeIdentifier(ClassMetadata $sourceMetadata, string $property): bool
    {
        $mapping = $sourceMetadata->getAssociationMapping($property);
        $targetEntity = $mapping['targetEntity'] ?? null;
        if (!\is_string($targetEntity)) {
            return false;
        }

        return $this->entityManager->getClassMetadata($targetEntity)->isIdentifierComposite;
    }

    /**
     * Materializes `$entities` to a `list<object>` (the preloader takes a list, and
     * the first element is the metadata representative).
     *
     * @param iterable<object> $entities
     *
     * @return list<object>
     */
    private function materialize(iterable $entities): array
    {
        if (\is_array($entities)) {
            return \array_values($entities);
        }

        return \iterator_to_array($entities, false);
    }
}
