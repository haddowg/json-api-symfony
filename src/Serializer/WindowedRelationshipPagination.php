<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Profile\ProfileInterface;
use haddowg\JsonApi\Schema\Relationship\RelationshipPagination;
use haddowg\JsonApi\Serializer\RelationshipPaginationInterface;

/**
 * The bundle's fill of core's {@see RelationshipPaginationInterface} seam (core
 * ADR 0058): a render-time lookup over a map the
 * {@see \haddowg\JsonApiBundle\DataProvider\RelationshipWindowBatcher} pre-computed
 * for the fetched page of parents under the Relationship Queries profile, so the
 * relationship-object pagination links core renders for a rendered to-many read a
 * pre-built page rather than triggering a per-object query at render time (bundle
 * ADR 0053).
 *
 * Keyed by the parent's object identity ({@see \spl_object_id()}) then by relation
 * name — exactly as {@see BatchedRelationshipCount} is — because the very object
 * instances the batcher windowed are the ones the serializer renders (the response
 * value object holds them), so the lookup needs no wire-id re-resolution. A
 * parent/relation absent from the map (a to-one, a relation not rendered, or a
 * provider that supplied no page) returns `null`, and core then emits no
 * relationship-object pagination links for it.
 *
 * The batcher writes the page-1 windowed/filtered/sorted linkage back onto each
 * parent's relation property as it builds this map, so the linkage `data` the
 * serializer reads off the parent and the pagination links here describe the same
 * page-1 of the profile-ordered set.
 *
 * Injected per request behind the {@see RequestScopedRelationshipPagination} holder
 * (which the handler swaps in only for a profile read), so it lives only for the
 * render of the page it was built from.
 */
final class WindowedRelationshipPagination implements RelationshipPaginationInterface
{
    /**
     * @param array<int, array<string, RelationshipPagination>> $pages `spl_object_id(parent) => [relationName => page]`
     */
    public function __construct(private readonly array $pages) {}

    public function paginateRelationship(
        mixed $model,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): ?RelationshipPagination {
        if (!\is_object($model)) {
            return null;
        }

        return $this->pages[\spl_object_id($model)][$relation->name()] ?? null;
    }

    /**
     * The distinct profiles the windowed included pages activate — a cursor-paginated
     * included relation renders a {@see \haddowg\JsonApi\Pagination\CursorBasedPage}
     * whose `profile()` is the cursor-pagination profile. The handler surfaces these
     * into the document's applied set ({@see \haddowg\JsonApi\Response\AbstractResponse::withActivatedProfiles()}),
     * so a cursor-resolved include advertises the profile even when the primary
     * collection is page-based.
     *
     * @return list<ProfileInterface>
     */
    public function activatedProfiles(): array
    {
        $profiles = [];
        $seen = [];
        foreach ($this->pages as $byRelation) {
            foreach ($byRelation as $pagination) {
                $profile = $pagination->page->profile();
                if ($profile !== null && !isset($seen[$profile->uri()])) {
                    $seen[$profile->uri()] = true;
                    $profiles[] = $profile;
                }
            }
        }

        return $profiles;
    }
}
