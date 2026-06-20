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
 * a non-root alias (the pivot related-collection path) targets the right alias. Bind
 * every value as a parameter (never interpolate the request value) and prefix any
 * placeholder distinctively to avoid colliding with the handler's own `jsonapi_`
 * parameters.
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
