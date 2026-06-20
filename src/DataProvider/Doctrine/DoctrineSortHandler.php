<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortHandlerInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Sort\UnsupportedSort;

/**
 * Executes a requested sort order against a Doctrine `QueryBuilder`: the
 * directives arrive most significant first (one composite call, core ADR 0016)
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
 * on the `pivot` join, a related key on the root — bundle ADR 0059). `apply()` is
 * `applyOn()` on the query root, so every non-pivot path stays byte-identical.
 *
 * A directive whose sort is not a {@see SortByField} is delegated to a registered
 * {@see DoctrineSortArmInterface} (constructor-injected from the autoconfigured tag,
 * first {@see DoctrineSortArmInterface::supports()} match wins) before
 * {@see UnsupportedSort} is raised — the Doctrine half of the framework's
 * extensible-handler seam.
 *
 * @implements SortHandlerInterface<QueryBuilder>
 * @implements AliasAwareSortHandler<QueryBuilder>
 */
final class DoctrineSortHandler implements SortHandlerInterface, AliasAwareSortHandler
{
    /**
     * @var list<DoctrineSortArmInterface>
     */
    private readonly array $arms;

    /**
     * @param iterable<DoctrineSortArmInterface> $arms author arms for custom sort types, consulted in order
     */
    public function __construct(iterable $arms = [])
    {
        $this->arms = \is_array($arms) ? \array_values($arms) : \iterator_to_array($arms, false);
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

        throw new UnsupportedSort($sort);
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
