<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\ForeignKey;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * A provider that reads a relation declared with a foreign key
 * (`HasMany::make('items', 'inbox-items', 'user')`) for the related type it serves.
 *
 * The {@see RelatedReadRouter} asks the related type's provider for every read of such a
 * relation, whatever provider serves the parents: the related and relationship
 * endpoints, an include, rendered linkage, the first pages and Relationship Queries
 * windows, and `?withCount`. A related type whose provider doesn't implement this is
 * refused at warm-up.
 *
 * The rows answered are the ones whose foreign key holds one of the parents' keys,
 * under the related type's own scope, filtered, sorted and windowed per `$criteria` as
 * the related endpoint's page is. The reference Doctrine provider runs each read as one
 * `WHERE <foreign key> IN (:keys)` query, windowed per parent.
 */
interface ReadsByForeignKeyInterface
{
    /**
     * Each parent's page of `$relation`, keyed by the parent's wire id: its window per
     * `$criteria`, or the whole filtered set when `$criteria` carries none. A to-one's
     * page holds its target, the first row, or none. A parent with no rows may be left
     * out.
     *
     * @throws \haddowg\JsonApi\Exception\FilterParamUnrecognized when a requested filter key is not declared
     * @throws \haddowg\JsonApi\Exception\SortParamUnrecognized   when a requested sort field is not declared
     */
    public function fetchByForeignKey(
        string $relatedType,
        RelationInterface $relation,
        ForeignKey $foreignKey,
        ForeignKeyParents $parents,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): RelatedBatch;

    /**
     * Each parent's count of `$relation`'s rows that survive `$criteria`'s filters, keyed
     * by the parent's wire id, `0` for a parent with none.
     *
     * @return array<string, int>
     *
     * @throws \haddowg\JsonApi\Exception\FilterParamUnrecognized when a requested filter key is not declared
     */
    public function countByForeignKey(
        string $relatedType,
        RelationInterface $relation,
        ForeignKey $foreignKey,
        ForeignKeyParents $parents,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): array;
}
