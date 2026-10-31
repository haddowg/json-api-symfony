<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Relationship\RelationshipLinkage;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationshipLinkage;
use haddowg\JsonApi\Serializer\RelationshipLinkageInterface;
use haddowg\JsonApi\Serializer\ToOneRelationshipLinkageInterface;
use haddowg\JsonApiBundle\Security\ReadableRows;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A stable, swappable holder for core's {@see RelationshipLinkageInterface} seam under
 * the Relationship Queries profile. Core consults the seam for every
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
 *
 * A to-one target the caller may not read through its own type's read gate is supplied
 * as none ({@see ReadableRows}), so it is missing from `data` and from
 * `included`. To-many linkage is never filtered here: a list holds what its query
 * returned. When nothing is refused it answers as it would without the check. A
 * `MorphTo` declared with `identifiedBy()` is checked only when its target was loaded:
 * the batcher loads every guarded type's targets, and the rest render their pairs.
 */
final class RequestScopedRelationshipLinkage implements ToOneRelationshipLinkageInterface, ResetInterface
{
    private ?RelationshipLinkageInterface $delegate = null;

    private ?RelationshipLinkageInterface $included = null;

    public function __construct(
        private readonly ?ReadableRows $rows = null,
    ) {}

    /**
     * Starts an operation on `$server`, named `$serverName`, deciding every row afresh.
     */
    public function bind(?SerializerResolverInterface $server, string $serverName = ServerProvider::DEFAULT_SERVER): void
    {
        $this->rows?->bind($server, $serverName);
    }

    /**
     * Decides every row afresh, after a write may have changed one.
     */
    public function forgetReadDecisions(): void
    {
        $this->rows?->forget();
    }

    /**
     * The first type `$relation` relates to whose collection read gate refuses the
     * caller, or `null` when every one admits them.
     */
    public function refusedCollectionType(RelationInterface $relation): ?string
    {
        return $this->rows?->refusedCollectionType($relation);
    }

    /**
     * A related endpoint's to-one target, or `null` when the caller may not read it.
     */
    public function readableTarget(mixed $target, RelationInterface $relation): mixed
    {
        // The endpoint has already enforced a permission the relation declares, which
        // replaces the target type's per-row gate.
        if ($relation->securityRead() !== null) {
            return $target;
        }

        return \is_object($target) && $this->rows !== null && !$this->rows->allows($target, $relation) ? null : $target;
    }

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

    /**
     * Installs (or clears) the scoped to-many linkage the
     * {@see \haddowg\JsonApiBundle\DataProvider\RelatedIncludeBatcher} loaded for the
     * read being handled. The windowed backing from {@see set()} wins
     * for a relation both supply, since it is the profile's page of the same scoped set.
     */
    public function setIncluded(?RelationshipLinkageInterface $included): void
    {
        $this->included = $included;
    }

    public function linkageForRelationship(
        mixed $model,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): ?RelationshipLinkage {
        return $this->delegate?->linkageForRelationship($model, $relation, $request)
            ?? $this->included?->linkageForRelationship($model, $relation, $request);
    }

    public function toOneLinkageForRelationship(
        mixed $model,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): ?ToOneRelationshipLinkage {
        $windowed = $this->delegate instanceof ToOneRelationshipLinkageInterface
            ? $this->delegate->toOneLinkageForRelationship($model, $relation, $request)
            : null;

        $linkage = $windowed ?? ($this->included instanceof ToOneRelationshipLinkageInterface
            ? $this->included->toOneLinkageForRelationship($model, $relation, $request)
            : null);

        if ($this->rows === null) {
            return $linkage;
        }

        // A permission the relation declares replaces the target type's per-row gate:
        // refused, the target reads as none (an `on()` hop included); allowed, it shows
        // under scoping alone.
        if ($relation->securityRead() !== null) {
            return \is_object($model) && $this->rows->relationPermission($model, $relation) === false
                ? ToOneRelationshipLinkage::none()
                : $linkage;
        }

        // The request's server picks the related type's gates; without one, the
        // server the operation bound.
        $serverName = $request->getAttribute('_jsonapi_server');
        $serverName = \is_string($serverName) && $serverName !== '' ? $serverName : null;

        if (!$this->rows->guardsRows($relation, $serverName)) {
            return $linkage;
        }

        // An identified MorphTo's pair renders without its target. The batcher loads the
        // targets of every guarded type, so one it didn't load needs no check.
        if ($linkage === null && $relation instanceof MorphTo && $relation->storesIdentifiers()) {
            return null;
        }

        $target = $linkage !== null ? $linkage->target : $relation->readValue($model, $request);

        return \is_object($target) && !$this->rows->allows($target, $relation, $serverName) ? ToOneRelationshipLinkage::none() : $linkage;
    }

    /**
     * Clears the backing between requests in a long-lived container (the `kernel.reset`
     * hook), so a profile read's windowed linkage never leaks into a later render that
     * does not re-set the holder.
     */
    public function reset(): void
    {
        $this->delegate = null;
        $this->included = null;
    }
}
