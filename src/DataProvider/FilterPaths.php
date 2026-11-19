<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\RelationHop;
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;
use haddowg\JsonApi\Resource\Filter\ThroughReadAccess;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Through\PivotRowRelations;
use haddowg\JsonApiBundle\DataProvider\Doctrine\Filter\WhereHasMatching;

/**
 * Resolves a traversal filter's path against the type its filters run against, through
 * core's {@see ThroughPathResolver}: the primary type on a collection fetch, the related
 * type on a related or relationship endpoint. The {@see CriteriaApplier} and the filter
 * value validator resolve each filter after matching the requested key, so a filter
 * family's filter resolves per request, and a path ending on a relationship compares
 * the related resource's id.
 *
 * They also carry what the caller may read through each relation a filter reaches
 * through: {@see hopsOf()} and {@see refusedHops()} decide which filters the caller may
 * not use, {@see relationAt()} names the relation a hop of a dotted path walks so a data
 * layer can apply the related type's scope to it, and {@see refuses()} tells it that a
 * hop admits no row at all.
 */
final readonly class FilterPaths
{
    /**
     * @param list<FieldInterface>                                                                $fields     the inventory of the type the filters run against
     * @param (\Closure(string): (iterable<FieldInterface>|null))|null                            $fieldsOf   a related type's inventory, or `null` when the type has none
     * @param string|null                                                                         $type       the type the filters run against
     * @param list<string>                                                                        $named      the types a filter's path may name, see {@see named()}
     * @param (\Closure(RelationInterface, list<object>, JsonApiRequestInterface): list<object>)|null $admission the rows a provider's scope admits of those a relation reached, see {@see admit()}
     * @param list<RelationInterface>                                                             $refused    the relations through which the filter being applied matches no row
     * @param RelationHop|null                                                                    $under      the relation the filters run under, on a relation's reads
     * @param (\Closure(RelationInterface, string, list<mixed>, JsonApiRequestInterface): list<mixed>)|null $morphAdmission the storage keys of a type a polymorphic relation stores that its provider admits, see {@see admitMorphTargets()}
     */
    public function __construct(
        private ThroughPathResolver $resolver,
        private array $fields,
        private ?\Closure $fieldsOf = null,
        private ?string $type = null,
        private array $named = [],
        private ?ThroughReadAccess $access = null,
        private ?\Closure $admission = null,
        private ?JsonApiRequestInterface $request = null,
        private array $refused = [],
        private ?RelationHop $under = null,
        private ?\Closure $morphAdmission = null,
    ) {}

    /**
     * The paths of filters running against `$resource`'s type, read over the types
     * `$resources` registers, or `null` when there is no resource and so no inventory.
     * `$admission` narrows the rows an in-memory walk reaches through a relation to those
     * the related type's provider admits. `$relation`, declared on `$parentType`, is the
     * relation the filters run under on its reads, and `$pivotRows` names the relations a
     * pivot row holds. `$morphAdmission` narrows the targets a `WhereMorph` matches to
     * those the target type's provider admits.
     *
     * @param (\Closure(RelationInterface, list<object>, JsonApiRequestInterface): list<object>)|null $admission
     * @param (\Closure(RelationInterface, string, list<mixed>, JsonApiRequestInterface): list<mixed>)|null $morphAdmission
     */
    public static function of(SerializerResolverInterface $resources, ?AbstractResource $resource, ?string $parentType = null, ?\Closure $admission = null, ?RelationInterface $relation = null, ?PivotRowRelations $pivotRows = null, ?\Closure $morphAdmission = null): ?self
    {
        if ($resource === null) {
            return null;
        }

        $under = $relation !== null && $parentType !== null ? new RelationHop($relation, $parentType, $relation->column() ?? $relation->name()) : null;

        return new self(ThroughPathResolver::forResolver($resources, $pivotRows), $resource->allFields(), static function (string $type) use ($resources): ?array {
            if (!$resources->hasSerializerFor($type)) {
                return null;
            }

            $serializer = $resources->serializerFor($type);

            return $serializer instanceof AbstractResource ? $serializer->allFields() : null;
        }, $resource::$type, self::namedBy($resource, $parentType), ThroughReadAccess::forResolver($resources, $pivotRows), $admission, under: $under, morphAdmission: $morphAdmission);
    }

    /**
     * The relation the filters run under, as the type declaring it holds it, on a
     * relation's reads.
     */
    public function under(): ?RelationHop
    {
        return $this->under;
    }

    /**
     * A copy for the filter about to run for `$request`, through whose `$refused`
     * relations it matches no row.
     *
     * @param list<RelationInterface> $refused
     */
    public function scoped(?JsonApiRequestInterface $request, array $refused = []): self
    {
        return new self($this->resolver, $this->fields, $this->fieldsOf, $this->type, $this->named, $this->access, $this->admission, $request, $refused, $this->under, $this->morphAdmission);
    }

    /**
     * The request the filter being applied runs for, whose caller the related types'
     * scopes narrow to, or null outside one.
     */
    public function request(): ?JsonApiRequestInterface
    {
        return $this->request;
    }

    /**
     * The relations `$filter` reaches through, as {@see ThroughReadAccess::hopsOf()} lists
     * them, and the relation of a {@see WhereHasMatching}.
     *
     * @return list<RelationHop>
     */
    public function hopsOf(FilterInterface $filter): array
    {
        if ($this->access === null || $this->type === null) {
            return [];
        }

        if ($filter instanceof WhereHasMatching) {
            return $this->access->hopsAlong($filter->relationship, $this->fields, $this->type);
        }

        return $this->access->hopsOf($filter, $this->fields, $this->type, $this->under);
    }

    /**
     * The relations `$filter` reaches through for the requested `$value`, as
     * {@see ThroughReadAccess::hopsOfRequested()} lists them: a `WhereMorph` reaches only
     * the type the value names.
     *
     * @return list<RelationHop>
     */
    public function hopsOfRequested(FilterInterface $filter, mixed $value): array
    {
        if ($this->access === null || $this->type === null || $filter instanceof WhereHasMatching) {
            return $this->hopsOf($filter);
        }

        return $this->access->hopsOfRequested($filter, $value, $this->fields, $this->type, $this->under);
    }

    /**
     * The hops of `$hops` the caller may not read through, asked of the server's gate.
     *
     * @param list<RelationHop> $hops
     *
     * @return list<RelationHop>
     */
    public function refusedHops(array $hops, JsonApiRequestInterface $request): array
    {
        return $hops === [] || $this->access === null ? [] : $this->access->refused($hops, $request);
    }

    /**
     * Whether the filter being applied matches no row through `$relation`.
     */
    public function refuses(RelationInterface $relation): bool
    {
        return \in_array($relation, $this->refused, true);
    }

    /**
     * The relation the last segment of the dotted storage path `$path` walks, or null
     * when that segment is no relation.
     */
    public function relationAt(string $path): ?RelationInterface
    {
        $relations = $this->relationsAlong($path);

        return \count($relations) === \count(\explode('.', $path)) ? $relations[\count($relations) - 1] : null;
    }

    /**
     * The rows of `$rows`, reached through `$relation`, that the related type's provider
     * admits for the caller: none through a refused relation, and all of them when no
     * provider narrows them or there is no request to narrow for.
     *
     * @param list<object> $rows
     *
     * @return list<object>
     */
    public function admit(RelationInterface $relation, array $rows): array
    {
        if ($this->refuses($relation)) {
            return [];
        }

        return $this->admission === null || $this->request === null ? $rows : ($this->admission)($relation, $rows, $this->request);
    }

    /**
     * The storage keys among `$keys`, targets of `$type` stored by the polymorphic
     * `$relation`, that the type's provider admits for the caller: none through a refused
     * relation, and all of them when no provider narrows them or there is no request to
     * narrow for.
     *
     * @param list<mixed> $keys
     *
     * @return list<mixed>
     */
    public function admitMorphTargets(RelationInterface $relation, string $type, array $keys): array
    {
        if ($this->refuses($relation)) {
            return [];
        }

        return $this->morphAdmission === null || $this->request === null ? $keys : ($this->morphAdmission)($relation, $type, $keys, $this->request);
    }

    /**
     * The types the declaration of `$resource`'s filters names: its own, the related
     * type of each relation it declares, and the parent type on a relation's reads.
     *
     * @return list<string>
     */
    public static function namedBy(AbstractResource $resource, ?string $parentType = null): array
    {
        $named = [$resource::$type];
        foreach ($resource->allFields() as $field) {
            if ($field instanceof RelationInterface) {
                $named = [...$named, ...$field->relatedTypes()];
            }
        }
        if ($parentType !== null) {
            $named[] = $parentType;
        }

        return \array_values(\array_unique($named));
    }

    /**
     * The types a filter's path may name: the type the filters run against, the related
     * types of its relations and, on a relation's reads, the parent type. A path whose
     * target entity several types serve with different id encoders decodes with the one
     * of these that serves it.
     *
     * @return list<string>
     */
    public function named(): array
    {
        return $this->named;
    }

    /**
     * The type the filters run against, whose id encoder decodes the ids they compare
     * with its own, or null when the paths were built without one.
     */
    public function type(): ?string
    {
        return $this->type;
    }

    /**
     * The declared relation each leading segment of a dotted storage path walks, from the
     * type the filters run against: the relation stored under the segment, the one named
     * after it when several share it, then the next segment on that relation's related
     * type. The list stops at the first segment that is not a monomorphic relation, so a
     * path ending on a field has one entry fewer than it has segments.
     *
     * @return list<RelationInterface>
     */
    public function relationsAlong(string $path): array
    {
        $resolver = $this->fieldsOf !== null ? new ThroughPathResolver($this->fieldsOf, $this->resolver->pivotRows()) : $this->resolver;

        return \array_map(static fn(array $stored): RelationInterface => $stored[0], $resolver->relationsAlong($path, $this->fields));
    }

    /**
     * @throws \LogicException when the path ends on a polymorphic relation or the filter cannot compare what it ends on
     */
    public function resolve(FilterInterface $filter): FilterInterface
    {
        return $this->resolver->resolve($filter, $this->fields, $this->under);
    }

    /**
     * `$sort` with a path reaching through relations resolved; any other sort unchanged.
     *
     * @throws \LogicException when the path cannot be resolved
     */
    public function resolveSort(SortInterface $sort): SortInterface
    {
        return $this->resolver->resolveSort($sort, $this->fields, $this->under);
    }

    /**
     * The relations `$sort` reaches through, as {@see ThroughReadAccess::hopsOfSort()}
     * lists them.
     *
     * @return list<RelationHop>
     */
    public function hopsOfSort(SortInterface $sort): array
    {
        return $this->access === null || $this->type === null ? [] : $this->access->hopsOfSort($sort, $this->fields, $this->type, $this->under);
    }

    /**
     * The admission an in-memory walk applies to the rows each hop reaches, for core's
     * reference handlers' `withRowAdmission()`: the relation a `->through()` hop names, or
     * else the one the walked storage path names, keeps the rows {@see admit()} lets
     * through. A segment that is no relation, and a row that is no object, stay as they
     * are.
     *
     * @return \Closure(string, list<mixed>, ?RelationInterface=): list<mixed>
     */
    public function rowAdmission(): \Closure
    {
        return $this->admitReached(...);
    }

    /**
     * @param list<mixed> $reached
     *
     * @return list<mixed>
     */
    private function admitReached(string $path, array $reached, ?RelationInterface $relation = null): array
    {
        $relation ??= $this->relationAt($path);
        if ($relation === null) {
            return $reached;
        }

        if ($this->refuses($relation)) {
            return [];
        }

        // Only an object row has a provider to ask; an array row stays as it is.
        $objects = \array_values(\array_filter($reached, \is_object(...)));
        if ($objects === []) {
            return $reached;
        }
        $admitted = [];
        foreach ($this->admit($relation, $objects) as $row) {
            $admitted[\spl_object_id($row)] = true;
        }

        return \array_values(\array_filter($reached, static fn(mixed $row): bool => !\is_object($row) || isset($admitted[\spl_object_id($row)])));
    }
}
