<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine\Filter;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;

/**
 * A **Doctrine-only** relationship-existence filter: keep a row whose named
 * relationship has at least one related record matching an author-supplied inner
 * predicate. The escape hatch for the cases the portable
 * {@see \haddowg\JsonApi\Resource\Filter\WhereThrough} vocabulary cannot express —
 * a multi-column / OR / NOT predicate, or raw DQL.
 *
 * Two construction surfaces, both feeding the SAME correlated `EXISTS` subquery
 * the bundle's {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineFilterHandler}
 * builds — rooted on the RELATED entity and correlated back to the outer owner, so
 * the inner predicate hangs naturally off the related root:
 *
 *  - {@see criteria()} — a {@see Criteria} applied with `addCriteria` on the related
 *    root (AND/OR/NOT over the related entity's columns; structured and safe);
 *  - {@see using()} — a `\Closure(QueryBuilder $sub, string $relatedAlias, mixed $value): void`
 *    deep hatch with raw access to the subquery, parameterised by the request value
 *    (the author owns its correctness and any parameter binding).
 *
 * NOT portable: it lives in the Doctrine namespace and is recognised only by the
 * Doctrine handler. On the in-memory provider the same `filter[<key>]` key is
 * undeclared, so the request is a clean `400` (the unrecognised-filter boundary,
 * exactly like the pivot-filter prefix) — never a silent non-match.
 *
 * NOT value-validated: the author owns the value (it is consumed by their closure,
 * not compared by a declared operator), so {@see constraints()} returns `[]`.
 */
final readonly class WhereHasMatching implements FilterInterface
{
    /**
     * @param \Closure(QueryBuilder, string, mixed): void|null $build
     */
    private function __construct(
        public string $key,
        public string $relationship,
        public ?Criteria $criteria = null,
        public ?\Closure $build = null,
    ) {}

    /**
     * Match rows whose `$relationship` has a related record satisfying `$criteria`
     * (applied with `addCriteria` on the related root). Responds to `filter[<key>]`.
     */
    public static function criteria(string $key, string $relationship, Criteria $criteria): self
    {
        return new self($key, $relationship, criteria: $criteria);
    }

    /**
     * Match rows whose `$relationship` has a related record the `$build` closure
     * narrows: it receives the related-rooted subquery, the related row alias, and
     * the request value, and adds predicates directly (binding its own parameters).
     * The deep hatch — the author owns correctness. Responds to `filter[<key>]`.
     *
     * @param \Closure(QueryBuilder, string, mixed): void $build
     */
    public static function using(string $key, string $relationship, \Closure $build): self
    {
        return new self($key, $relationship, build: $build);
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * The author owns the request value (it is consumed by the closure, never
     * compared by a declared operator), so there is nothing for the bridge to
     * validate.
     *
     * @return list<ConstraintInterface>
     */
    public function constraints(): array
    {
        return [];
    }
}
