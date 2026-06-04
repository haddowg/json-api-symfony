<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortHandlerInterface;
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
 * @implements SortHandlerInterface<QueryBuilder>
 */
final class DoctrineSortHandler implements SortHandlerInterface
{
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

        foreach ($sorts as $directive) {
            $sort = $directive->sort;
            if (!$sort instanceof SortByField) {
                throw new UnsupportedSort($sort);
            }

            $query->addOrderBy($this->path($query, $sort->column), $directive->descending ? 'DESC' : 'ASC');
        }

        return $query;
    }

    /**
     * The DQL path for a declared sort column on the root entity, validated as
     * an identifier path (dots allowed for embedded fields).
     */
    private function path(QueryBuilder $query, string $column): string
    {
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $column) !== 1) {
            throw new \LogicException(\sprintf('"%s" is not a valid Doctrine field path.', $column));
        }

        $alias = $query->getRootAliases()[0]
            ?? throw new \LogicException('The QueryBuilder has no root alias to sort on.');

        return $alias . '.' . $column;
    }
}
