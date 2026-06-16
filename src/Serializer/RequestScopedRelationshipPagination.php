<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\RelationshipPagination;
use haddowg\JsonApi\Serializer\RelationshipPaginationInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A stable, swappable holder for core's {@see RelationshipPaginationInterface}
 * seam (core ADR 0058) under the Relationship Queries profile (bundle ADR 0053).
 * Core consults the seam for EVERY rendered to-many relation to learn the page-1
 * pagination state it emits as the relationship object's `first`/`prev`/`next`
 * (+`last`) links — but the actual page is per request (it depends on the
 * profile's per-relationship sort/filter and the fetched parents), so the value
 * behind the seam must be swappable without rebuilding the memoized
 * {@see \haddowg\JsonApi\Server\Server}.
 *
 * This holder is that indirection, mirroring {@see RequestScopedRelationshipCount}:
 * the {@see \haddowg\JsonApiBundle\Server\ServerFactory} threads it through core's
 * {@see \haddowg\JsonApi\Server\Server::withRelationshipPagination()} once, and the
 * {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler} swaps a
 * {@see WindowedRelationshipPagination} backing in on each read whose request
 * negotiated the profile — and clears it (`null`) otherwise — so the render pass
 * that follows {@see \haddowg\JsonApi\Server\Server::dispatch()} reads exactly the
 * page just windowed and never a previous request's. With no backing set it
 * answers `null` for every relation, so core emits no relationship-object
 * pagination links — exactly as before this seam existed (the profile-not-negotiated
 * default).
 *
 * Like the count holder, the read arms re-set or clear it on every read but a
 * write/linkage arm renders without touching it, so in a long-lived container (a
 * worker reusing the kernel across messages) a prior profile read could leave a
 * backing set and a later render could read a stale page. It therefore implements
 * {@see ResetInterface} (auto-tagged `kernel.reset`): the container resets it
 * between messages, so no request inherits a previous one's windowed pages.
 */
final class RequestScopedRelationshipPagination implements RelationshipPaginationInterface, ResetInterface
{
    private ?RelationshipPaginationInterface $delegate = null;

    /**
     * Installs (or clears, with `null`) the windowed page-1 backing for the read
     * currently being handled, so the render that follows reads this request's
     * relationship pages. The handler calls it on every read, so a read whose
     * request did not negotiate the profile clears any backing a prior request
     * installed.
     */
    public function set(?RelationshipPaginationInterface $delegate): void
    {
        $this->delegate = $delegate;
    }

    public function paginateRelationship(
        mixed $model,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): ?RelationshipPagination {
        return $this->delegate?->paginateRelationship($model, $relation, $request);
    }

    /**
     * Clears the backing between requests in a long-lived container (the
     * `kernel.reset` hook), so a profile read's windowed pages never leak into a
     * later render that does not re-set the holder.
     */
    public function reset(): void
    {
        $this->delegate = null;
    }
}
