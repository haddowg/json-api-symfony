<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortInterface;
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
 *    `belongsToMany` declaring `pivotFields()` is backed by an association entity
 *    discovered from metadata (its `through()` override, or an auto-detect scan); the
 *    {@see PivotAssociationResolver} already fails loudly, but lazily on the first
 *    write. Running the discovery here moves only the TIMING — the same
 *    `\LogicException` fires at `cache:warmup`.
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
                if ($type === '' || !isset($this->entityClassByType[$type])) {
                    continue; // only entity-mapped types reach the Doctrine adapter
                }

                $metadata = $this->entityManager->getClassMetadata($this->entityClassByType[$type]);
                $resource = $this->typeMetadata->resourceFor($server, $type);

                if ($resource !== null) {
                    $this->guardSorts($type, $resource->allSorts(), $metadata);
                    $this->guardFilters($type, $resource->filters(), $metadata);
                }

                $this->guardPivots($serverName, $type, $metadata);
            }
        }

        // No preloadable class files: a pure build-time guard.
        return [];
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
     * embedded) is left to its own path.
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
            default => null,
        };
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
            if (!$relation instanceof BelongsToMany || $relation->pivotFields() === []) {
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
