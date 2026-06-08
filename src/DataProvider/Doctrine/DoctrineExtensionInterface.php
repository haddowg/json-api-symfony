<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\QueryBuilder;

/**
 * Customizes every QueryBuilder the {@see DoctrineDataProvider} executes for a
 * resource type, **before** the requested criteria are applied — the seam for
 * base constraints the client must not be able to undo (soft-delete exclusion,
 * tenant scoping, published-only) and for query shaping (eager-loading joins).
 *
 * Implementations are discovered by autoconfiguration (tagged
 * {@see \haddowg\JsonApiBundle\JsonApiBundle::DOCTRINE_EXTENSION_TAG}) and every
 * extension whose {@see supports()} matches is applied, in descending tag
 * `priority` order. Because extensions run first, requested `filter[…]`/`sort`
 * parameters always compose *on top of* (`AND` onto) the customized query, the
 * pre-window COUNT of a paginated fetch is taken from the customized builder
 * (totals agree with the scope), and a single fetch whose row the scope
 * excludes is a JSON:API `404`.
 *
 * {@see QueryPurpose} says why the query is being built; per its contract,
 * apply constraints unconditionally and branch only to exempt a specific
 * purpose. Bound parameter names are the extension's own responsibility — any
 * name not prefixed `jsonapi_` is safe (the bundle's handlers generate theirs
 * under that prefix, collision-free against parameters already bound).
 */
interface DoctrineExtensionInterface
{
    /**
     * Whether this extension applies to the given resource type.
     */
    public function supports(string $type): bool;

    /**
     * Returns the customized builder. The builder arrives with the root entity
     * selected (the root alias is readable via `$builder->getRootAliases()`)
     * and, for {@see QueryPurpose::FetchOne}, the identifier constraint already
     * bound.
     */
    public function apply(QueryBuilder $builder, string $type, QueryPurpose $purpose): QueryBuilder;
}
