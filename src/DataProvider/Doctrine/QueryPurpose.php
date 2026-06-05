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
 * fails closed.
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
}
