<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Serializer\RelationshipCountInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A stable, swappable holder for the per-request count seam (bundle ADR 0052).
 * Core's {@see RelationshipCountInterface} is injected into the immutable, memoized
 * {@see \haddowg\JsonApi\Server\Server} once (at factory time, mirroring the
 * Doctrine load-state predicate); but the actual counts are per request — they
 * depend on the fetched page — so the value behind the seam must be swappable
 * without rebuilding the Server.
 *
 * This holder is that indirection: the {@see \haddowg\JsonApiBundle\Server\ServerFactory}
 * threads it through {@see \haddowg\JsonApi\Server\Server::withRelationshipCount()}
 * once, and the {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler} swaps
 * its {@see BatchedRelationshipCount} backing in on each read — and clears it (sets
 * `null`) on a read that named no `?withCount` — so the render pass that follows
 * {@see \haddowg\JsonApi\Server\Server::dispatch()} consults exactly the page just
 * fetched and never a previous request's counts. With no backing set it answers
 * `null` and core omits `meta.total`, exactly as if no seam were wired.
 *
 * The read arms re-set or clear the holder on every read, but a write/linkage arm
 * renders without touching it — so in a **long-lived container** (a worker /
 * messenger consumer that reuses the kernel across messages) a prior `?withCount`
 * read could leave a backing set, and a later write/linkage render of a countable
 * relationship object would read a stale count (the {@see BatchedRelationshipCount}
 * keys parents by {@see \spl_object_id()}, which the runtime reuses after GC, so the
 * leak surfaces as a wrong `meta.total`, not an error). It therefore implements
 * {@see ResetInterface} (auto-tagged `kernel.reset`): the container resets it between
 * messages, clearing the backing so no request inherits a previous one's counts.
 * Per-request FPM never hits this — a fresh container nulls the holder — but the
 * bundle targets worker-capable architectures, so the reset is load-bearing.
 */
final class RequestScopedRelationshipCount implements RelationshipCountInterface, ResetInterface
{
    private ?RelationshipCountInterface $delegate = null;

    /**
     * Installs (or clears, with `null`) the batched counts for the read currently
     * being handled, so the render that follows reads this page's counts. The
     * handler calls it on every read, so a read with no `?withCount` clears any
     * counts a prior request installed.
     */
    public function set(?RelationshipCountInterface $delegate): void
    {
        $this->delegate = $delegate;
    }

    public function countRelationship(mixed $model, RelationInterface $relation): ?int
    {
        return $this->delegate?->countRelationship($model, $relation);
    }

    /**
     * Clears the backing between requests in a long-lived container (the
     * `kernel.reset` hook), so a `?withCount` read's counts never leak into a later
     * write/linkage render that does not re-set the holder.
     */
    public function reset(): void
    {
        $this->delegate = null;
    }
}
