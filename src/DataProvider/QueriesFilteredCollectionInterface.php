<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApiBundle\Action\ActionDescriptor;

/**
 * A provider that can hand a collection-scope custom action the rows its `filter[…]`
 * parameters select. {@see \haddowg\JsonApiBundle\Action\ActionContext::filtered()} reads
 * through it, and throws when the type's provider does not implement it.
 *
 * The criteria carries the action's validated filter map and the filters it takes, in
 * the type's order, and no sort or window. Apply the provider's own access scope first,
 * then the filters with their declared defaults through
 * {@see CriteriaApplier::applyFilters()}, so the rows are the ones a collection fetch
 * with the same filters would list.
 */
interface QueriesFilteredCollectionInterface
{
    /**
     * A fresh native query for the filtered rows, such as a Doctrine `QueryBuilder`, with
     * the access scope and the filters applied and no sort, window or count. Null when
     * the provider has no native query to hand out.
     */
    public function queryFiltered(string $type, CollectionCriteria $criteria, ActionDescriptor $action): ?object;

    /**
     * The filtered rows in ascending id order, read `$batch` rows at a time.
     *
     * @param positive-int $batch
     *
     * @return iterable<object>
     */
    public function eachFiltered(string $type, CollectionCriteria $criteria, ActionDescriptor $action, int $batch): iterable;
}
