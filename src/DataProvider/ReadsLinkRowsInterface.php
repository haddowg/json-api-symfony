<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Schema\Relationship\IdentifiedMember;

/**
 * A provider that reads the link rows of a `MorphToMany` declared with
 * `identifiedByRows()` for a whole page of parents at once, so rendering their linkage
 * doesn't load each parent's rows in turn. The include and linkage walk asks the parent
 * type's provider; without it each parent's rows are read off the parent.
 */
interface ReadsLinkRowsInterface
{
    /**
     * Each parent's members, keyed by its position in `$parents`, every position present,
     * each member carrying its row. A row that links no declared type, or has no id, is
     * left out, and a member a parent links twice is kept once.
     *
     * @param list<object> $parents
     *
     * @return array<int, list<IdentifiedMember>>
     */
    public function linkRowMembers(array $parents, MorphToMany $relation, JsonApiRequestInterface $request): array;
}
