<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\ForeignKey;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\TraversesPath;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereGroup;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereMorph;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use haddowg\JsonApi\Resource\Filter\WhereSet;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApiBundle\DataProvider\AbstractDataProvider;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;
use haddowg\JsonApiBundle\DataProvider\FilterPaths;
use haddowg\JsonApiBundle\DataProvider\RelatedReadRouter;
use haddowg\JsonApiBundle\DataProvider\RelationCriteriaFactory;
use haddowg\JsonApiBundle\Server\IdEncoderResolver;
use haddowg\JsonApiBundle\Server\RouteDescriptorRegistry;
use haddowg\JsonApiBundle\Server\ServerProvider;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * The Doctrine half of the build-time servability guard family: it asserts, at
 * `cache:warmup`, that the reference Doctrine adapter can actually execute the
 * sort / filter vocabulary and resolve the pivot associations a registered,
 * entity-mapped type declares — so a misconfiguration fails the BUILD
 * (`cache:clear` / deploy) instead of a runtime `QueryException` 500 (or a first-write
 * `\LogicException`). It is the storage-aware twin of the provider-agnostic
 * {@see \haddowg\JsonApiBundle\Server\ServableResourceWarmer} and
 * {@see \haddowg\JsonApiBundle\Serializer\EagerLoadWarmer}; like both it is **not
 * optional** ({@see isOptional()} returns `false`), so its `\LogicException`
 * propagates out of `cache:warmup` and aborts the deploy.
 *
 * Registered only when the Doctrine reference adapter is wired (the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass}
 * fills the `type → entity` map and removes this warmer alongside the
 * provider/persister when no resource maps an entity), so a non-Doctrine application
 * never runs it. For every entity-mapped type it checks, against the entity's
 * Doctrine {@see ClassMetadata}:
 *
 *  - **Sortable / filterable columns resolve to a real field or association.** A
 *    field marked `->sortable()` / `->filterable()` that is `computed()` (no backing
 *    column) yields a sort / filter whose target column is the field NAME; the
 *    Doctrine handler then emits `alias.<name>` and Doctrine throws a `QueryException`
 *    at request time. The RESOLVED column is checked — so a `computed()` sortable
 *    field that ALSO has a matching `sorts()` override (which supplies a real column)
 *    PASSES — and only single-segment columns are checked, so a pivot-routed
 *    (`pivot.position`) or embedded (`meta.x`) column is left to its own path.
 *  - **A pivot `belongsToMany` resolves its association entity at build.** A
 *    `belongsToMany` declaring a stored pivot field is backed by an association entity
 *    discovered from metadata (one whose pivot fields are all computed reads its plain
 *    join table and is skipped) (its `through()` override, or an auto-detect scan); the
 *    {@see PivotAssociationResolver} already fails loudly, but lazily on the first
 *    write. Running the discovery here moves only the TIMING — the same
 *    `\LogicException` fires at `cache:warmup`.
 *  - **A foreign key is a mapped column of the related entity.** A relation declared
 *    with a foreign key into an entity-mapped type reads through a field or a
 *    single-valued association of that entity, and an association must reference the
 *    parent's own entity.
 *  - **A relation of a parent that is no entity names how to reach its rows.** A
 *    `HasMany`, `BelongsToMany` or `HasOne` into an entity-mapped type, declared on a
 *    type no entity maps, has no association to scope the related rows by. It must
 *    declare a foreign key or `extractUsing()`, or the parent type's provider must batch
 *    its own related reads.
 */
final class DoctrineServableWarmer implements CacheWarmerInterface
{
    /**
     * @param array<string, class-string> $entityClassByType a `type → entity FQCN` map (filled by the DoctrineEntityMapPass)
     * @param list<string>                 $serverNames       the declared server names (including the implicit `default`)
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly array $entityClassByType,
        private readonly ServerProvider $servers,
        private readonly RouteDescriptorRegistry $descriptors,
        private readonly TypeMetadataResolver $typeMetadata,
        private readonly PivotAssociationResolver $pivotAssociations,
        private readonly array $serverNames,
        private readonly ?IdEncoderResolver $idEncoders = null,
        private readonly ?DataProviderRegistry $providers = null,
    ) {}

    /**
     * Not optional: an unexecutable sort / filter column or an unresolvable pivot
     * MUST fail the build, so the `\LogicException` propagates out of `cache:warmup`
     * rather than surfacing as a runtime 500.
     */
    public function isOptional(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     *
     * @throws \LogicException when a sortable/filterable column does not resolve to a
     *                         real Doctrine field/association, or a pivot relation's
     *                         association entity cannot be discovered
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        foreach ($this->serverNames as $serverName) {
            $server = $this->servers->get($serverName);

            foreach (\array_keys($this->descriptors->forServer($serverName)) as $type) {
                if ($type !== '') {
                    $this->guardForeignKeyReads($serverName, $type);
                }

                if ($type === '' || !isset($this->entityClassByType[$type])) {
                    continue; // only entity-mapped types reach the Doctrine adapter
                }

                $metadata = $this->entityManager->getClassMetadata($this->entityClassByType[$type]);
                $resource = $this->typeMetadata->resourceFor($server, $type);

                if ($resource !== null) {
                    $this->guardSorts($type, $resource->allSorts(), $metadata);
                    $this->guardFilters($type, $resource->allFilters(), $metadata);
                    $this->guardTargetEncoders($serverName, $type, $resource, $metadata);
                    $this->guardMorphFilters($serverName, $type, $resource, $metadata);
                }

                $this->guardPivots($serverName, $type, $metadata);
            }
        }

        // No preloadable class files: a pure build-time guard.
        return [];
    }

    /**
     * Asserts each relation of `$type` into an entity-mapped type has a way to its rows:
     * a foreign key the related entity maps, an association of the parent's entity, an
     * `extractUsing()` closure, or a parent provider that batches its own related reads.
     */
    private function guardForeignKeyReads(string $serverName, string $type): void
    {
        $server = $this->servers->get($serverName);
        $resource = $this->typeMetadata->resourceFor($server, $type);
        if ($resource === null) {
            return;
        }

        $parentClass = $this->entityClassByType[$type] ?? null;
        foreach ($resource->allFields() as $relation) {
            if (!$relation instanceof RelationInterface || \count($relation->relatedTypes()) !== 1) {
                continue;
            }

            $relatedType = $relation->relatedTypes()[0];
            $relatedClass = $this->entityClassByType[$relatedType] ?? null;
            if ($relatedClass === null) {
                continue;
            }

            $name = ForeignKey::nameOf($relation);
            if ($name !== null) {
                $this->guardForeignKeyColumn($server, $type, $parentClass, $relation, $relatedType, $relatedClass, $name);

                continue;
            }

            if ($parentClass !== null || !($relation instanceof HasMany || $relation instanceof HasOne)
                || RelatedReadRouter::extractsValue($relation) || $this->parentBatches($type)) {
                continue;
            }

            throw new \LogicException(\sprintf(
                'Relation "%s" on resource type "%s" reads type "%s", which Doctrine serves, but "%s" is not a Doctrine entity, so no association reaches its related rows. %s',
                $relation->name(),
                $type,
                $relatedType,
                $type,
                $relation instanceof BelongsToMany
                    ? 'Compute its members with extractUsing(), or have the provider serving "' . $type . '" batch its related reads.'
                    : \sprintf(
                        'Name the field or to-one relation of "%s" holding the parent\'s id as the foreign key argument, %s::make(\'%s\', \'%s\', \'<foreign key>\'), or compute it with extractUsing().',
                        $relatedType,
                        $relation instanceof HasOne ? 'HasOne' : 'HasMany',
                        $relation->name(),
                        $relatedType,
                    ),
            ));
        }
    }

    /**
     * Asserts the foreign key `$name` is stored in a field or single-valued association
     * of the related entity, and that an association references the parent's entity.
     *
     * @param class-string|null $parentClass
     * @param class-string      $relatedClass
     */
    private function guardForeignKeyColumn(\haddowg\JsonApi\Server\Server $server, string $type, ?string $parentClass, RelationInterface $relation, string $relatedType, string $relatedClass, string $name): void
    {
        $foreignKey = ForeignKey::of($relation, $server);
        if ($foreignKey === null) {
            return; // core's RelationForeignKeyValidator names what is wrong with it
        }

        $metadata = $this->entityManager->getClassMetadata($relatedClass);
        $column = $foreignKey->column;
        $problem = null;
        if ($metadata->hasAssociation($column)) {
            $target = $metadata->getAssociationTargetClass($column);
            $problem = match (true) {
                !$metadata->isSingleValuedAssociation($column) => 'a collection association',
                $parentClass === null => \sprintf('an association to %s, which can\'t reference "%s", a type no entity maps; store its id in a field instead', $target, $type),
                !\is_a($parentClass, $target, true) && !\is_a($target, $parentClass, true) => \sprintf('an association to %s, not to %s', $target, $parentClass),
                default => null,
            };
        } elseif (!$metadata->hasField($column)) {
            $problem = 'neither a mapped field nor an association';
        }

        if ($problem !== null) {
            throw new \LogicException(\sprintf(
                'Relation "%s" on resource type "%s" reads through the foreign key "%s", stored in "%s", which %s maps as %s.',
                $relation->name(),
                $type,
                $name,
                $column,
                $relatedClass,
                $problem,
            ));
        }
    }

    /**
     * Whether the provider serving `$type` batches its own related reads, which it does
     * unless it keeps {@see AbstractDataProvider}'s unsupported default.
     */
    private function parentBatches(string $type): bool
    {
        if ($this->providers === null || !$this->providers->supportsType($type)) {
            return false;
        }

        $provider = $this->providers->forType($type);
        if (!$provider instanceof AbstractDataProvider) {
            return true;
        }

        return (new \ReflectionMethod($provider, 'fetchRelatedCollectionBatch'))->getDeclaringClass()->getName() !== AbstractDataProvider::class;
    }

    /**
     * Asserts every field-derived / declared sort whose target is a simple column
     * resolves to a real field or association on the entity. A custom
     * {@see SortInterface} that carries no column (a relation-count / computed sort
     * the author handles through an arm) is left to its arm; a dotted column (pivot /
     * embedded) is left to its own path.
     *
     * @param list<SortInterface>   $sorts
     * @param ClassMetadata<object> $metadata
     */
    private function guardSorts(string $type, array $sorts, ClassMetadata $metadata): void
    {
        foreach ($sorts as $sort) {
            if (!$sort instanceof SortByField) {
                continue; // a custom sort owns its own column resolution (an author arm)
            }

            $column = $sort->column;
            if (\str_contains($column, '.')) {
                continue; // a pivot-routed / embedded column is left to its own path
            }

            if (!$metadata->hasField($column) && !$metadata->hasAssociation($column)) {
                throw new \LogicException(\sprintf(
                    'The sort "%s" on JSON:API type "%s" targets column "%s", which is not a field or '
                    . 'association on %s. A computed() field marked sortable() has no backing column (its '
                    . 'sort column defaults to the field name) — give it a real column via sorts() keyed by '
                    . 'the same name, or drop sortable().',
                    $sort->key(),
                    $type,
                    $column,
                    $metadata->getName(),
                ));
            }
        }
    }

    /**
     * Asserts every column-targeting filter resolves to a real field or association on
     * the entity. Only the plain column filters carry a single-segment column; a
     * relationship-existence / traversal filter ({@see \haddowg\JsonApi\Resource\Filter\WhereHas},
     * {@see \haddowg\JsonApi\Resource\Filter\WhereThrough},
     * {@see \haddowg\JsonApi\Resource\Filter\WhereHasMatching}) or a custom filter
     * targets a relationship / path, not a column, and a dotted column (pivot /
     * embedded) is left to its own path. A filter family resolves its filters only
     * against a request, so neither it nor the filters it returns can be checked here.
     *
     * @param list<FilterInterface> $filters
     * @param ClassMetadata<object> $metadata
     */
    private function guardFilters(string $type, array $filters, ClassMetadata $metadata): void
    {
        foreach ($filters as $filter) {
            $column = $this->filterColumn($filter);
            if ($column === null || \str_contains($column, '.')) {
                continue; // a relationship/custom filter, or a pivot-routed / embedded column
            }

            if (!$metadata->hasField($column) && !$metadata->hasAssociation($column)) {
                throw new \LogicException(\sprintf(
                    'The filter "%s" on JSON:API type "%s" targets column "%s", which is not a field or '
                    . 'association on %s. A computed() field marked filterable() has no backing column (its '
                    . 'filter column defaults to the field name) — give the filter a real column, or drop '
                    . 'filterable().',
                    $filter->key(),
                    $type,
                    $column,
                    $metadata->getName(),
                ));
            }
        }
    }

    /**
     * The single-segment column a column-targeting filter resolves to, or `null` for
     * a filter that targets a relationship / path (so the warmer leaves it to its own
     * handler). The convenience filter library (Contains/StartsWith/… , Numeric,
     * GreaterThan, DateRange) extends {@see Where} / {@see Range}, so it is covered.
     */
    private function filterColumn(FilterInterface $filter): ?string
    {
        return match (true) {
            $filter instanceof Where => $filter->column,
            $filter instanceof Range => $filter->column,
            $filter instanceof WhereIn => $filter->column,
            $filter instanceof WhereNotIn => $filter->column,
            $filter instanceof WhereNull => $filter->column,
            $filter instanceof WhereNotNull => $filter->column,
            $filter instanceof WhereSet => $filter->column,
            default => null,
        };
    }

    /**
     * Refuses a {@see WhereMorph} the Doctrine provider cannot query: one over a `MorphTo`
     * that doesn't declare `identifiedByColumns()`, or whose fields aren't fields of the
     * entity, and one over a `MorphToMany` that doesn't declare `identifiedByRows()`. The
     * type's own filters are checked, and each relation's against the related type.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function guardMorphFilters(string $serverName, string $type, AbstractResource $resource, ClassMetadata $metadata): void
    {
        $server = $this->servers->get($serverName);
        $this->guardMorphFiltersOf($type, $resource->allFilters(), $metadata, FilterPaths::of($server, $resource));

        foreach ($resource->allFields() as $relation) {
            if (!$relation instanceof RelationInterface || \count($relation->relatedTypes()) !== 1) {
                continue;
            }
            $relatedType = $relation->relatedTypes()[0];
            $related = $this->typeMetadata->resourceFor($server, $relatedType);
            if ($related === null || !isset($this->entityClassByType[$relatedType])) {
                continue;
            }

            $this->guardMorphFiltersOf($relatedType, $relation->allFilters(), $this->entityManager->getClassMetadata($this->entityClassByType[$relatedType]), FilterPaths::of($server, $related, $type));
        }
    }

    /**
     * @param list<FilterInterface> $filters
     * @param ClassMetadata<object> $metadata the entity the filters run against
     */
    private function guardMorphFiltersOf(string $type, array $filters, ClassMetadata $metadata, ?FilterPaths $paths): void
    {
        foreach ($filters as $filter) {
            if ($filter instanceof WhereGroup) {
                $this->guardMorphFiltersOf($type, $filter->children, $metadata, $paths);

                continue;
            }
            if (!$filter instanceof WhereMorph || $paths === null) {
                continue;
            }

            $resolved = $paths->resolve($filter);
            $relation = $resolved instanceof WhereMorph ? $resolved->resolvedTarget()?->relation : null;
            if ($relation instanceof MorphToMany && $relation->linkRowFields() !== null) {
                continue;
            }

            $columns = $relation instanceof MorphTo ? $relation->identifierColumns() : null;
            if ($columns === null) {
                throw new \LogicException(\sprintf(
                    'The filter "%s" on JSON:API type "%s" is a WhereMorph over the relation "%s", which the Doctrine provider cannot query: %s',
                    $filter->key(),
                    $type,
                    $filter->relation,
                    $relation instanceof MorphToMany
                        ? 'declare the MorphToMany with through() and identifiedByRows(), naming its link rows\' owner, type and id fields.'
                        : 'declare the MorphTo with identifiedByColumns(type: ..., id: ...), naming the entity fields that hold its stored type and id. A closure from identifiedBy() can\'t be compared in a query.',
                ));
            }

            foreach ([$columns->type, $columns->id] as $field) {
                if (!$metadata->hasField($field)) {
                    throw new \LogicException(\sprintf(
                        'The filter "%s" on JSON:API type "%s" is a WhereMorph over the relation "%s", whose identifiedByColumns() names "%s", which is not a mapped field of %s.',
                        $filter->key(),
                        $type,
                        $filter->relation,
                        $field,
                        $metadata->getName(),
                    ));
                }
            }
        }
    }

    /**
     * Refuses a traversal filter whose path ends on an entity, through an association or
     * a foreign key no declared relation names, that several types serve with different
     * id encoders when no type the declaration names serves it: the filter could not tell
     * which encoder decodes the ids it is sent. The type's own filters are checked with
     * the types its declaration names, and each relation's filters with those of the
     * related type plus this one.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function guardTargetEncoders(string $serverName, string $type, AbstractResource $resource, ClassMetadata $metadata): void
    {
        if ($this->idEncoders === null) {
            return;
        }

        $server = $this->servers->get($serverName);
        $encoders = new DoctrineTargetEncoders($this->entityManager, $this->entityClassByType, $this->idEncoders);
        $this->guardTargetsOf($serverName, $type, $resource->allFilters(), $metadata, FilterPaths::of($server, $resource), $encoders);

        foreach ($resource->allFields() as $relation) {
            if (!$relation instanceof RelationInterface || \count($relation->relatedTypes()) !== 1) {
                continue;
            }
            $relatedType = $relation->relatedTypes()[0];
            $related = $this->typeMetadata->resourceFor($server, $relatedType);
            if ($related === null || !isset($this->entityClassByType[$relatedType])) {
                continue;
            }

            $this->guardTargetsOf($serverName, $type, $relation->allFilters(), $this->entityManager->getClassMetadata($this->entityClassByType[$relatedType]), FilterPaths::of($server, $related, $type), $encoders, $relation, $metadata);
        }
    }

    /**
     * @param list<FilterInterface>      $filters
     * @param ClassMetadata<object>      $metadata       the entity the filters run against
     * @param ClassMetadata<object>|null $parentMetadata the entity declaring `$relation`
     */
    private function guardTargetsOf(string $serverName, string $type, array $filters, ClassMetadata $metadata, ?FilterPaths $paths, DoctrineTargetEncoders $encoders, ?RelationInterface $relation = null, ?ClassMetadata $parentMetadata = null): void
    {
        foreach ($filters as $filter) {
            if ($filter instanceof WhereGroup) {
                $this->guardTargetsOf($serverName, $type, $filter->children, $metadata, $paths, $encoders, $relation, $parentMetadata);

                continue;
            }
            if (!$filter instanceof WhereThrough && !$filter instanceof WhereThroughIn) {
                continue;
            }

            // A pivot-row filter walks the pivot entity's associations from the row.
            if ($relation instanceof BelongsToMany && $relation->storedPivotFields() !== [] && $parentMetadata !== null && RelationCriteriaFactory::startsFromPivotRow($filter)) {
                /** @var class-string $parentClass */
                $parentClass = $parentMetadata->getName();
                /** @var class-string $relatedClass */
                $relatedClass = $metadata->getName();
                $pivot = $this->pivotAssociations->discover($relation, $parentClass, $relatedClass);
                $target = $this->pathTarget($this->entityManager->getClassMetadata($pivot->entityClass), \substr($filter->path, \strlen('pivot.')), null);
            } else {
                $resolved = $paths?->resolve($filter);
                if ($resolved instanceof TraversesPath && $resolved->resolvedPath()?->endsOnRelation() === true) {
                    continue; // the relation the path ends on names the type
                }

                $target = $this->pathTarget($metadata, $filter->path, $paths);
            }
            if ($target === null) {
                continue;
            }

            try {
                $encoders->encoderFor($target, $paths?->named() ?? [], $serverName);
            } catch (AmbiguousTargetEncoder $e) {
                throw new \LogicException(\sprintf(
                    'The JSON:API resource for type "%s" on server "%s" declares a filter that cannot run: filter "%s"%s ends its path "%s" on %s, which the types "%s" serve with different id encoders, and no type the declaration names serves it. Declare the path through a relation to the type it means, or give those types one encoder.',
                    $type,
                    $serverName,
                    $filter->key(),
                    $relation !== null ? \sprintf(' of relation "%s"', $relation->name()) : '',
                    $filter->path,
                    $e->entityClass,
                    \implode('", "', $e->types),
                ), 0, $e);
            }
        }
    }

    /**
     * The entity a traversal path ends on when its last segment is an association, or a
     * field mapping one's foreign key, rather than a declared relation: the entity whose
     * ids the filter compares. Null for a path ending on another field, or one this walk
     * cannot follow, which the filter reports when it runs.
     *
     * @param ClassMetadata<object> $metadata
     *
     * @return class-string|null
     */
    private function pathTarget(ClassMetadata $metadata, string $path, ?FilterPaths $paths): ?string
    {
        $segments = \explode('.', $path);
        $relations = $paths?->relationsAlong($path) ?? [];
        foreach ($segments as $index => $segment) {
            $last = $index === \count($segments) - 1;
            if (!$metadata->hasAssociation($segment)) {
                $relation = $relations[$index] ?? null;
                if ($relation instanceof BelongsToMany && $relation->storedPivotFields() !== [] && isset($this->entityClassByType[$relation->relatedTypes()[0] ?? ''])) {
                    /** @var class-string $owner */
                    $owner = $metadata->getName();
                    $pivot = $this->pivotAssociations->discover($relation, $owner, $this->entityClassByType[$relation->relatedTypes()[0]]);
                    $metadata = $this->entityManager->getClassMetadata($this->entityManager->getClassMetadata($pivot->entityClass)->getAssociationTargetClass($pivot->farProperty));
                    if ($last) {
                        return $metadata->getName();
                    }

                    continue;
                }

                $foreignKey = $last ? DoctrineIdentifiers::foreignKeyAssociation($metadata, $segment) : null;

                return $foreignKey === null ? null : $metadata->getAssociationTargetClass($foreignKey);
            }

            $target = $metadata->getAssociationTargetClass($segment);
            if ($last) {
                return $target;
            }
            $metadata = $this->entityManager->getClassMetadata($target);
        }

        return null;
    }

    /**
     * Discovers the association entity backing every pivot `belongsToMany` declared on
     * the type, so the {@see PivotAssociationResolver}'s `\LogicException` (a missing
     * `through()` and no, or an ambiguous, auto-detected entity) fires at
     * `cache:warmup` instead of the first relationship write. Only the resolvability
     * is asserted — the discovery is otherwise identical to (and cached for) the
     * runtime path.
     *
     * @param ClassMetadata<object> $parentMetadata
     */
    private function guardPivots(string $serverName, string $type, ClassMetadata $parentMetadata): void
    {
        $server = $this->servers->get($serverName);

        foreach ($this->typeMetadata->relationsFor($server, $type) as $relation) {
            // Only a stored pivot field needs an association entity; a relation whose
            // pivot fields are all computed reads its plain join table.
            if (!$relation instanceof BelongsToMany || $relation->storedPivotFields() === []) {
                continue;
            }

            $relatedType = $relation->relatedTypes()[0] ?? null;
            if ($relatedType === null || !isset($this->entityClassByType[$relatedType])) {
                continue; // a far type with no Doctrine entity cannot be discovered here
            }

            /** @var class-string $parentClass */
            $parentClass = $parentMetadata->getName();

            // Reuses the runtime discovery message verbatim — only the timing moves.
            $this->pivotAssociations->discover($relation, $parentClass, $this->entityClassByType[$relatedType]);
        }
    }
}
