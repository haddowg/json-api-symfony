<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

/**
 * The pair the {@see \haddowg\JsonApiBundle\DataProvider\RelationshipWindowBatcher}
 * computes for a fetched page of parents under the Relationship Queries profile: the
 * windowed relationship-object PAGINATION (core's `first`/`prev`/`next` (+`last`)
 * links) and the windowed LINKAGE data (the page-1 filtered/sorted set, supplied to
 * core out-of-band so the page is never written onto the parent property — bundle ADR
 * 0086).
 *
 * The handler installs each member into its own request-scoped holder
 * ({@see RequestScopedRelationshipPagination} / {@see RequestScopedRelationshipLinkage})
 * so core renders the windowed page's links AND its linkage data without the batcher
 * mutating any parent. Either member may be `null` (a relation re-ordered/filtered but
 * not sliced supplies linkage with no pagination).
 */
final readonly class WindowedRelationshipResult
{
    public function __construct(
        public ?WindowedRelationshipPagination $pagination,
        public ?WindowedRelationshipLinkage $linkage,
    ) {}
}
