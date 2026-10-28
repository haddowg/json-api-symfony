<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

/**
 * Why the {@see DoctrineDataProvider} is building the query a
 * {@see DoctrineExtensionInterface} is customizing. Today every query is a
 * read; the write phase adds purposes for the persister's target loads (the
 * entity an update or delete acts on is fetched through the same extension
 * pipeline, so a scoping rule holds for writes without re-declaration).
 *
 * The case list is therefore **non-exhaustive by design**: an extension must
 * not enumerate purposes to opt *in* (an exhaustive `match` would make a
 * tenancy or soft-delete scope silently stop applying when a new purpose
 * appears). Apply the constraint unconditionally and branch on a purpose only
 * to *exempt* one you have a specific reason to treat differently — scoping
 * fails closed. {@see IncludeHydration} is the one purpose a query-shaping extension
 * opts *in* to, because its ids are already scoped.
 */
enum QueryPurpose
{
    /**
     * The windowed, criteria-filtered `GET /{type}` collection query (and its
     * pre-window COUNT, which is derived from the same builder — totals always
     * agree with the applied scope).
     */
    case FetchCollection;

    /**
     * The single-resource `GET /{type}/{id}` lookup. A row the scope excludes
     * is absent, so the handler renders a JSON:API `404`.
     */
    case FetchOne;

    /**
     * A related-collection load: the `GET /{type}/{id}/{rel}` related endpoint,
     * a relationship-linkage scope, a `?include` batch (the to-one id-IN load,
     * the to-many batch, the pivot collection), a to-one filter probe, or a
     * `?withCount` member load — every query the provider builds *for a related
     * type while serving another type's request*.
     *
     * It is distinct from {@see FetchCollection} so a request-aware scope can
     * tell a primary `GET /{type}` collection from a related load of the same
     * type (the two reported the same purpose before this case existed). The
     * related call sites also carry the request on {@see ExtensionContext}, so
     * an extension can read it here; a primary fetch carries `null`.
     */
    case FetchRelatedCollection;

    /**
     * The `WHERE id IN (…)` load that hydrates included rows whose ids an earlier
     * query already chose: the many-to-many and owning-side to-many include batch
     * selects (parent, related) id pairs under {@see FetchRelatedCollection}, then
     * loads the related entities by those ids under this purpose.
     *
     * The ids are already scoped, so a filtering extension may ignore the purpose and
     * apply again (it changes nothing) or exempt it. A query-shaping extension, such as
     * a fetch-join of an association the included rows render, can opt in here to
     * load that association in the same query.
     *
     * The to-one include load is not this purpose: its ids are read off the parents
     * unscoped, so the id load is the scope and reports {@see FetchRelatedCollection}.
     */
    case IncludeHydration;
}
