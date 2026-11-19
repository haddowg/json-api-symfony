<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortHandlerInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Sort\UnsupportedSort;
use haddowg\JsonApiBundle\DataProvider\FilterPaths;
use haddowg\JsonApiBundle\DataProvider\ReadsFilterPathsInterface;

/**
 * Executes a requested sort order against a Doctrine `QueryBuilder`: the
 * directives arrive most significant first (one composite call)
 * and append as sequential `addOrderBy` terms, so the request's first `sort`
 * field is the primary key, as the spec requires. Any non-{@see SortByField}
 * directive (a computed/multi-column sort) has no generic DQL translation and
 * raises {@see UnsupportedSort} — a resource declaring one supplies its own
 * handler/provider instead.
 *
 * The handler is also the bundle's {@see AliasAwareSortHandler}: the shared
 * {@see \haddowg\JsonApiBundle\DataProvider\CriteriaApplier} can append a directive
 * on a NON-root alias of the query so the pivot related-collection path builds its
 * whole `ORDER BY` in the request's directive order across two aliases (a pivot key
 * on the `pivot` join, a related key on the root). `apply()` is
 * `applyOn()` on the query root, so every non-pivot path stays byte-identical.
 *
 * A directive whose sort is not a {@see SortByField} is delegated to a registered
 * {@see DoctrineSortArmInterface} (constructor-injected from the autoconfigured tag,
 * first {@see DoctrineSortArmInterface::supports()} match wins) before
 * {@see UnsupportedSort} is raised — the Doctrine half of the framework's
 * extensible-handler seam.
 *
 * A {@see SortByField} declared with `->through()` left-joins each to-one relation of its
 * path, every join narrowed to the rows the reached type's query extensions admit, none
 * through a relation a default order may not read, and
 * orders by the leaf column of the entity the last join reaches. A relation that holds
 * nothing, or a row a scope hides, leaves the join null, so the row sorts as a null
 * column does. On a cursor page the level reads its boundary value from the query.
 *
 * @implements SortHandlerInterface<QueryBuilder>
 * @implements AliasAwareSortHandler<QueryBuilder>
 */
final class DoctrineSortHandler implements SortHandlerInterface, AliasAwareSortHandler, ReadsFilterPathsInterface
{
    /**
     * Data-layer-specific remediation appended to the core {@see UnsupportedSort}
     * message when a custom sort reaches this Doctrine handler with no arm to run it.
     */
    private const string ARM_HINT = 'To run a custom sort on the Doctrine provider, register a DoctrineSortArmInterface (tag: json_api.doctrine_sort_arm) — see the "custom filters and sorts: the arm seam" section of the doctrine docs.';

    /**
     * The remediation when a custom sort orders a cursor page and its arm cannot.
     */
    private const string KEYSET_ARM_HINT = 'To order a cursor page by a custom sort on the Doctrine provider, its arm must implement DoctrineKeysetSortArmInterface — see the "custom sorts under a cursor" section of the doctrine docs.';

    /**
     * @var list<DoctrineSortArmInterface>
     */
    private readonly array $arms;

    private ?FilterPaths $paths = null;

    /**
     * @param iterable<DoctrineSortArmInterface>                         $arms          author arms for custom sort types, consulted in order
     * @param (\Closure(string): list<DoctrineExtensionInterface>)|null $extensionsFor the query extensions scoping a type, applied to every row a `->through()` sort reaches
     */
    public function __construct(iterable $arms = [], private readonly ?\Closure $extensionsFor = null)
    {
        $this->arms = \is_array($arms) ? \array_values($arms) : \iterator_to_array($arms, false);
    }

    public function withPaths(FilterPaths $paths, \Closure $apply): mixed
    {
        $previous = $this->paths;
        $this->paths = $paths;
        try {
            return $apply();
        } finally {
            $this->paths = $previous;
        }
    }

    public function apply(array $sorts, mixed $query): mixed
    {
        if (!$query instanceof QueryBuilder) {
            throw new \LogicException(\sprintf(
                'The %s expects a %s query; got %s.',
                self::class,
                QueryBuilder::class,
                \get_debug_type($query),
            ));
        }

        return $this->applyOn($sorts, $query, $this->rootAlias($query));
    }

    public function applyOn(array $directives, mixed $query, string $alias): mixed
    {
        if (!$query instanceof QueryBuilder) {
            throw new \LogicException(\sprintf(
                'The %s expects a %s query; got %s.',
                self::class,
                QueryBuilder::class,
                \get_debug_type($query),
            ));
        }

        foreach ($directives as $directive) {
            $sort = $directive->sort;
            if ($sort instanceof SortByField && $sort->through !== null) {
                $query->addOrderBy($this->throughColumn($sort, $query, $alias)[0], $directive->descending ? 'DESC' : 'ASC');

                continue;
            }

            if ($sort instanceof SortByField) {
                $query->addOrderBy($this->path($sort->column, $alias), $directive->descending ? 'DESC' : 'ASC');

                continue;
            }

            $this->applyArm($sort, $query, $directive->descending, $alias);
        }

        return $query;
    }

    /**
     * Delegates a custom {@see SortInterface} to the first registered
     * {@see DoctrineSortArmInterface} that {@see DoctrineSortArmInterface::supports()}
     * it; {@see UnsupportedSort} when none does (the same signal the built-in gave).
     */
    private function applyArm(SortInterface $sort, QueryBuilder $query, bool $descending, string $alias): void
    {
        foreach ($this->arms as $arm) {
            if ($arm->supports($sort)) {
                $arm->apply($sort, $query, $descending, $alias);

                return;
            }
        }

        throw new UnsupportedSort($sort, self::ARM_HINT);
    }

    /**
     * The keyset expression for a custom sort level of a cursor page, from the first
     * registered arm that {@see DoctrineSortArmInterface::supports()} `$sort`. That arm
     * must be a {@see DoctrineKeysetSortArmInterface}; otherwise, or with no arm at
     * all, the level is an {@see UnsupportedSort}.
     */
    public function keysetExpression(SortInterface $sort, QueryBuilder $query, string $alias): KeysetExpression
    {
        if ($sort instanceof SortByField && $sort->through !== null) {
            [$column, $metadata, $field] = $this->throughColumn($sort, $query, $alias);

            // The boundary row's value is the one the scoped joins reached, so the cursor
            // reads it from the query rather than walking the entity's relations.
            return new KeysetExpression($column, $metadata->hasField($field) ? $metadata->getTypeOfField($field) : null, valueFromQuery: true);
        }

        foreach ($this->arms as $arm) {
            if (!$arm->supports($sort)) {
                continue;
            }

            if (!$arm instanceof DoctrineKeysetSortArmInterface) {
                throw new UnsupportedSort($sort, self::KEYSET_ARM_HINT);
            }

            return $arm->keysetExpression($sort, $query, $alias);
        }

        throw new UnsupportedSort($sort, self::KEYSET_ARM_HINT);
    }

    /**
     * Whether a cursor page can order by `$sort`: a field sort always, a custom sort
     * when the first arm supporting it implements {@see DoctrineKeysetSortArmInterface}.
     */
    public function keysCursorSort(SortInterface $sort): bool
    {
        if ($sort instanceof SortByField) {
            return true;
        }

        foreach ($this->arms as $arm) {
            if ($arm->supports($sort)) {
                return $arm instanceof DoctrineKeysetSortArmInterface;
            }
        }

        return false;
    }

    /**
     * Left-joins each relation of `$sort`'s resolved path off `$alias`, once per query,
     * and returns the leaf column's DQL path, the metadata of the entity it is on and the
     * field it names.
     *
     * @return array{string, ClassMetadata<object>, string}
     */
    private function throughColumn(SortByField $sort, QueryBuilder $query, string $alias): array
    {
        $leaf = $sort->atLeaf();
        $path = $sort->resolvedThrough();
        \assert($path !== null);

        $entityManager = $query->getEntityManager();
        // A path starting with `pivot` reads the pivot row the relation's pivot query
        // joined as `pivot`, whichever alias the sort is applied on: a cursor level asks
        // on the root.
        $hops = $path->hops;
        if ($path->startsWithPivot()) {
            $alias = 'pivot';
            unset($hops[0]);
        }
        $metadata = $this->metadataOfAlias($query, $alias)
            ?? throw new \LogicException($path->startsWithPivot()
                ? \sprintf('Sort "%s" reaches through "%s", which starts at the pivot row of the relation it runs under, but it runs on a query that joins no pivot row.', $sort->key(), $path->path)
                : \sprintf('The QueryBuilder has no entity at alias "%s" to sort on.', $alias));
        $current = $alias;
        $joined = self::joinedAliases($query);
        foreach ($hops as $index => $hop) {
            if ($hop->pivot) {
                throw new \LogicException(\sprintf('Sort "%s" reaches through "%s", whose pivot row a sort can read only at the start of its path.', $sort->key(), $path->path));
            }
            $association = $hop->storage();
            if (!$metadata->hasAssociation($association) || !$metadata->isSingleValuedAssociation($association)) {
                throw new \LogicException(\sprintf('Sort "%s" reaches through "%s", but "%s" is not a to-one association on "%s".', $sort->key(), $path->path, $association, $metadata->getName()));
            }
            $next = 'jsonapi_through_' . \substr(\md5($alias . '|' . \implode('.', \array_slice($path->storageSegments(), 0, $index + 1))), 0, 12);
            $target = $entityManager->getClassMetadata($metadata->getAssociationTargetClass($association));
            if (!isset($joined[$next])) {
                $condition = $this->scopeCondition($query, $next, $target, $hop->relatedType(), $hop->relation);
                $condition === null
                    ? $query->leftJoin($current . '.' . $association, $next)
                    : $query->leftJoin($current . '.' . $association, $next, 'WITH', $condition);
                $joined[$next] = true;
            }
            $current = $next;
            $metadata = $target;
        }

        return [$this->path($leaf->column, $current), $metadata, $leaf->column];
    }

    /**
     * The join condition keeping only the rows the caller may read through `$relation`:
     * none through a relation a default order may not read, else the rows `$type`'s query
     * extensions admit ({@see ExtensionScope}), or null when nothing narrows them.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function scopeCondition(QueryBuilder $query, string $alias, ClassMetadata $metadata, string $type, \haddowg\JsonApi\Resource\Field\RelationInterface $relation): ?string
    {
        if ($this->paths?->refuses($relation) === true) {
            return '1 = 0';
        }

        $extensions = $this->extensionsFor !== null ? ($this->extensionsFor)($type) : [];

        return ExtensionScope::condition($query, $alias, $metadata, $type, $extensions, $this->paths?->request());
    }

    /**
     * @return array<string, true>
     */
    private static function joinedAliases(QueryBuilder $query): array
    {
        $aliases = [];
        /** @var array<string, list<\Doctrine\ORM\Query\Expr\Join>> $joins */
        $joins = $query->getDQLPart('join');
        foreach ($joins as $list) {
            foreach ($list as $join) {
                $aliases[(string) $join->getAlias()] = true;
            }
        }

        return $aliases;
    }

    /**
     * The metadata of the entity `$alias` names on `$query`: a `FROM` root or an entity
     * joined off another alias.
     *
     * @return ClassMetadata<object>|null
     */
    private function metadataOfAlias(QueryBuilder $query, string $alias): ?ClassMetadata
    {
        $entityManager = $query->getEntityManager();
        /** @var list<\Doctrine\ORM\Query\Expr\From> $froms */
        $froms = $query->getDQLPart('from');
        foreach ($froms as $from) {
            if ($from->getAlias() === $alias) {
                return $entityManager->getClassMetadata($from->getFrom());
            }
        }

        /** @var array<string, list<\Doctrine\ORM\Query\Expr\Join>> $joins */
        $joins = $query->getDQLPart('join');
        foreach ($joins as $list) {
            foreach ($list as $join) {
                if ($join->getAlias() !== $alias) {
                    continue;
                }
                $target = $join->getJoin();
                if (!\str_contains($target, '.')) {
                    return \class_exists($target) ? $entityManager->getClassMetadata($target) : null;
                }
                [$parentAlias, $association] = \explode('.', $target, 2);
                $parent = $parentAlias === $alias ? null : $this->metadataOfAlias($query, $parentAlias);

                return $parent !== null && $parent->hasAssociation($association)
                    ? $entityManager->getClassMetadata($parent->getAssociationTargetClass($association))
                    : null;
            }
        }

        return null;
    }

    /**
     * The DQL path for a declared sort column on `$alias` (the query root by
     * default, the join alias on the pivot path), validated as an identifier path
     * (dots allowed for embedded fields).
     */
    private function path(string $column, string $alias): string
    {
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $column) !== 1) {
            throw new \LogicException(\sprintf('"%s" is not a valid Doctrine field path.', $column));
        }

        return $alias . '.' . $column;
    }

    private function rootAlias(QueryBuilder $query): string
    {
        return $query->getRootAliases()[0]
            ?? throw new \LogicException('The QueryBuilder has no root alias to sort on.');
    }
}
