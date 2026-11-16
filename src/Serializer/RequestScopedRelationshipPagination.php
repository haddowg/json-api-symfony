<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\RelationshipPagination;
use haddowg\JsonApi\Serializer\RelationshipPaginationInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A stable, swappable holder for core's {@see RelationshipPaginationInterface} seam.
 * Core consults the seam for every rendered to-many relation to learn the first-page
 * pagination state it emits as the relationship object's `first`/`prev`/`next`/`last`
 * links, but the page is per request (it depends on the fetched parents and any
 * Relationship Queries sort/filter), so the value behind the seam must be swappable
 * without rebuilding the memoized {@see \haddowg\JsonApi\Server\Server}.
 *
 * The {@see \haddowg\JsonApiBundle\Server\ServerFactory} threads it through core's
 * {@see \haddowg\JsonApi\Server\Server::withRelationshipPagination()} once. The
 * {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler} installs two backings on
 * each read: the Relationship Queries pages of the primary resources ({@see set()},
 * `null` when the profile was not negotiated) and the first pages the include walk cut
 * at every level ({@see setIncluded()}). With neither set it answers `null` for every
 * relation, so core emits no relationship-object pagination links.
 *
 * A write or linkage arm can render without re-setting it, so in a long-lived container
 * a prior read could leave a backing set. It therefore implements
 * {@see ResetInterface} (auto-tagged `kernel.reset`): the container resets it between
 * messages, so no request inherits a previous one's pages.
 */
final class RequestScopedRelationshipPagination implements RelationshipPaginationInterface, ResetInterface
{
    private ?RelationshipPaginationInterface $delegate = null;

    private ?RelationshipPaginationInterface $included = null;

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

    /**
     * Installs (or clears) the first pages the
     * {@see \haddowg\JsonApiBundle\DataProvider\RelatedIncludeBatcher} cut for the read
     * being handled. A page from {@see set()} wins for a relation both supply, since it is
     * the Relationship Queries page of the same relation.
     */
    public function setIncluded(?RelationshipPaginationInterface $included): void
    {
        $this->included = $included;
    }

    public function paginateRelationship(
        mixed $model,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): ?RelationshipPagination {
        return $this->delegate?->paginateRelationship($model, $relation, $request)
            ?? $this->included?->paginateRelationship($model, $relation, $request);
    }

    /**
     * Clears the backing between requests in a long-lived container (the
     * `kernel.reset` hook), so a profile read's windowed pages never leak into a
     * later render that does not re-set the holder.
     */
    public function reset(): void
    {
        $this->delegate = null;
        $this->included = null;
    }
}
