<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Filter\FilterInterface;

/**
 * A **self-applying** Doctrine filter: it carries its own query fragment — a named
 * repository / query-builder method (`->active()`), a `Doctrine\Common\Collections\Criteria`
 * applied with `addCriteria`, or raw DQL — so it needs **no** separate
 * {@see DoctrineFilterArmInterface} service to run.
 *
 * The self-applying twin of the arm seam: where an arm is a registered service keyed on
 * a filter's class, this puts the application on the filter value object itself. The
 * {@see DoctrineFilterHandler} consults it **before** the arm registry (the built-ins
 * still win), so a one-off, dependency-free custom filter is fully defined by its own VO
 * — the execution counterpart of the {@see \haddowg\JsonApiBundle\Validation\Constraint\NativeConstraints}
 * carrier for validation. Reach for an arm instead when the application needs injected
 * services (a `Security`, a repository).
 *
 * Pair it with core's {@see \haddowg\JsonApi\Resource\Filter\DescribesQueryParameter} to
 * also document a non-scalar `filter[<key>]` parameter, and a filter becomes wholly
 * self-contained — value schema, OpenAPI shape, and execution — in one class.
 *
 * **Doctrine-only, and not portable.** It lives in the Doctrine namespace and runs only
 * on the Doctrine provider; the same `filter[<key>]` key is undeclared on the in-memory
 * provider, so a request there is a clean `400` (the unrecognised-filter boundary) — never
 * a silent non-match. A filter that must run on both providers ships a portable
 * {@see FilterInterface} plus an arm per store instead.
 *
 * Always bind the request value as a query parameter — never interpolate it into DQL —
 * and derive a collision-free parameter name off the running parameter count (the same
 * discipline {@see DoctrineFilterArmInterface} documents), clear of the reserved
 * `jsonapi_` prefix the handler uses for its own bindings.
 */
interface AppliesToQueryBuilder extends FilterInterface
{
    /**
     * Applies this filter to `$query` on `$alias` against the request `$value` — typically
     * one or more `andWhere` predicates, or `$query->addCriteria(...)`.
     */
    public function applyToQueryBuilder(QueryBuilder $query, mixed $value, string $alias): void;
}
