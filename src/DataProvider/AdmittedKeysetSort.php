<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Sort\KeysetSortInterface;
use haddowg\JsonApi\Resource\Sort\SortByField;

/**
 * A cursor level of a sort reaching through relations, reading its value through the
 * rows the reached types' providers admit, so a row a scope hides sorts as null on an
 * in-memory cursor page as it does on Doctrine's.
 *
 * @internal
 */
final readonly class AdmittedKeysetSort implements KeysetSortInterface
{
    /**
     * @param \Closure(string, list<mixed>, ?RelationInterface=): list<mixed> $admit
     */
    public function __construct(
        private SortByField $sort,
        private \Closure $admit,
    ) {}

    public function key(): string
    {
        return $this->sort->key();
    }

    public function keysetValue(mixed $row): mixed
    {
        return $this->sort->valueOf($row, $this->admit);
    }
}
