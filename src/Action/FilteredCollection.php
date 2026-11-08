<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Action;

use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\QueriesFilteredCollectionInterface;

/**
 * The rows of the mount type a collection-scope action's `filter[…]` parameters select,
 * from {@see ActionContext::filtered()}. The filters were validated before the handler
 * ran, and the type's access scope applies, so these are the rows a collection fetch with
 * the same filters would list. Nothing is read until {@see query()} or {@see each()} is
 * called.
 */
final readonly class FilteredCollection
{
    /**
     * @internal built by the {@see ActionInvoker}
     */
    public function __construct(
        private QueriesFilteredCollectionInterface $provider,
        private string $type,
        private CollectionCriteria $criteria,
        private ActionDescriptor $action,
    ) {}

    /**
     * The provider's native query builder for the rows: a Doctrine `QueryBuilder` for the
     * Doctrine provider, with the type's extensions and the filters applied and no sort,
     * window or count. Each call returns a new builder, so the handler can add a select,
     * an update or its own order. Null for a provider with no native builder, such as the
     * in-memory one.
     */
    public function query(): ?object
    {
        return $this->provider->queryFiltered($this->type, $this->criteria, $this->action);
    }

    /**
     * Every row, in ascending id order, read `$batch` rows at a time. Each batch starts
     * after the last id of the one before, so rows the handler changes or removes do not
     * shift the next batch.
     *
     * The Doctrine provider clears the EntityManager between batches to keep memory flat.
     * Every entity loaded before that, including rows from earlier batches and anything
     * else the request loaded, is detached at that point: flush the changes of a batch
     * before asking for the next, and do not hold on to entities across batches.
     *
     * @return iterable<object>
     */
    public function each(int $batch = 500): iterable
    {
        if ($batch < 1) {
            throw new \InvalidArgumentException(\sprintf('The batch size must be at least 1, %d given.', $batch));
        }

        return $this->provider->eachFiltered($this->type, $this->criteria, $this->action, $batch);
    }
}
