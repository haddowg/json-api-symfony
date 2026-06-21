<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\RelationshipLinkage;
use haddowg\JsonApi\Serializer\RelationshipLinkageInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A stable, swappable holder for core's {@see RelationshipLinkageInterface} seam under
 * the Relationship Queries profile (bundle ADR 0086). Core consults the seam for every
 * rendered to-many relation to learn whether its linkage `data` is supplied out-of-band
 * (the windowed page) rather than read off the parent property — but the actual page is
 * per request (it depends on the profile's per-relationship sort/filter and the fetched
 * parents), so the value behind the seam must be swappable without rebuilding the
 * memoized {@see \haddowg\JsonApi\Server\Server}.
 *
 * This holder is that indirection, mirroring {@see RequestScopedRelationshipPagination}:
 * the {@see \haddowg\JsonApiBundle\Server\ServerFactory} threads it through core's
 * {@see \haddowg\JsonApi\Server\Server::withRelationshipLinkage()} once, and the
 * {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler} swaps a
 * {@see WindowedRelationshipLinkage} backing in on each read whose request negotiated
 * the profile — and clears it (`null`) otherwise — so the render pass reads exactly the
 * page just windowed and never a previous request's. With no backing set it answers
 * `null` for every relation, so core reads linkage off the model — exactly as before
 * this seam existed (the profile-not-negotiated default).
 *
 * Like the pagination holder, a write/linkage arm renders without re-setting it, so in a
 * long-lived container a prior profile read could leave a backing set and a later render
 * could read a stale page. It therefore implements {@see ResetInterface} (auto-tagged
 * `kernel.reset`): the container resets it between messages.
 */
final class RequestScopedRelationshipLinkage implements RelationshipLinkageInterface, ResetInterface
{
    private ?RelationshipLinkageInterface $delegate = null;

    /**
     * Installs (or clears, with `null`) the windowed linkage backing for the read
     * currently being handled, so the render that follows reads this request's windowed
     * relationship linkage. The handler calls it on every read, so a read whose request
     * did not negotiate the profile clears any backing a prior request installed.
     */
    public function set(?RelationshipLinkageInterface $delegate): void
    {
        $this->delegate = $delegate;
    }

    public function linkageForRelationship(
        mixed $model,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): ?RelationshipLinkage {
        return $this->delegate?->linkageForRelationship($model, $relation, $request);
    }

    /**
     * Clears the backing between requests in a long-lived container (the `kernel.reset`
     * hook), so a profile read's windowed linkage never leaks into a later render that
     * does not re-set the holder.
     */
    public function reset(): void
    {
        $this->delegate = null;
    }
}
