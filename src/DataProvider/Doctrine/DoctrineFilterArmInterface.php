<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Filter\FilterInterface;

/**
 * An extension "arm" for {@see DoctrineFilterHandler}: it pushes ONE custom
 * {@see FilterInterface} type down to a Doctrine `QueryBuilder`. Implement it,
 * register it as a service (autoconfigured by this interface — no manual tag), and
 * the Doctrine provider consults every registered arm for any filter its built-ins
 * do not recognise (first {@see supports()} match wins) before raising core's
 * {@see \haddowg\JsonApi\Resource\Filter\UnsupportedFilter}. The built-ins always
 * win — an arm is a fallthrough, never an override of `Where`/`WhereIn`/…
 *
 * This is the data-layer twin of core's in-memory
 * {@see \haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterArmInterface}: a
 * **portable** custom filter ships both (the in-memory arm is the conformance
 * witness, this arm the production push-down) and the two stay behaviourally
 * identical under the shared conformance suite; an inherently Doctrine-specific
 * filter (a raw-DQL scope) ships only this one.
 *
 * The arm receives the same `$alias` the built-in path uses, so a filter applied on
 * a non-root alias (the pivot related-collection path) targets the right alias.
 *
 * Always bind the request value as a query parameter — never interpolate it into the
 * DQL. The arm receives the live `$query`, NOT a pre-allocated placeholder name or
 * index, so the author owns placeholder uniqueness: a filter may run several times in
 * one request (each declared `filter[...]` key) and may bind more than one parameter,
 * so a fixed name (`:value`) collides and silently overwrites an earlier binding.
 * Derive a collision-free name the same way the built-ins do — off the running
 * parameter count — and keep clear of the reserved `jsonapi_` prefix the handler uses
 * for its own bindings:
 *
 * ```php
 * public function apply(FilterInterface $filter, QueryBuilder $query, mixed $value, string $alias): void
 * {
 *     $name = 'arm_' . \count($query->getParameters()); // unique per binding, no `jsonapi_` clash
 *     $query->andWhere(\sprintf('%s.title LIKE :%s', $alias, $name))
 *         ->setParameter($name, '%' . $value . '%');
 * }
 * ```
 */
interface DoctrineFilterArmInterface
{
    /**
     * Whether this arm executes `$filter`. Keyed on the filter's concrete type
     * (`$filter instanceof MyFilter`), not its key — one arm backs one filter
     * value-object class.
     */
    public function supports(FilterInterface $filter): bool;

    /**
     * Applies `$filter` to `$query` on `$alias` against the request `$value`
     * (typically one or more `andWhere` predicates). Only called when
     * {@see supports()} returned `true`.
     */
    public function apply(FilterInterface $filter, QueryBuilder $query, mixed $value, string $alias): void;
}
