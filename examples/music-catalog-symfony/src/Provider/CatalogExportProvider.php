<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Provider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\DataProvider\RelatedBatch;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Model\CatalogExport;

/**
 * The read half of the model-less `catalog-exports` type: a tiny custom provider
 * over a fixed list, so `GET /catalog-exports` / `GET /catalog-exports/{id}` reuse the
 * shared {@see \haddowg\JsonApiBundle\DataProvider\CriteriaApplier} + array window via a
 * delegated {@see InMemoryDataProvider}. Writes go through the paired
 * {@see CatalogExportPersister} (async accept). The finished export a completed
 * `export-jobs` redirects to lives here.
 *
 * @implements DataProviderInterface<object>
 */
final class CatalogExportProvider implements DataProviderInterface
{
    private readonly InMemoryDataProvider $inner;

    public function __construct()
    {
        $this->inner = new InMemoryDataProvider('catalog-exports', [
            '1' => new CatalogExport(id: '1', format: 'csv', status: 'ready'),
            '2' => new CatalogExport(id: '2', format: 'json', status: 'ready'),
        ]);
    }

    public function supports(string $type): bool
    {
        return $type === 'catalog-exports';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return $this->inner->fetchOne($type, $id);
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return $this->inner->fetchCollection($type, $criteria);
    }

    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult {
        return $this->inner->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request);
    }

    public function fetchRelatedCollectionBatch(
        string $parentType,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): RelatedBatch {
        return $this->inner->fetchRelatedCollectionBatch($parentType, $parents, $relation, $criteria, $request);
    }

    public function fetchRelationshipPivot(string $type, object $parent, RelationInterface $relation): array
    {
        return $this->inner->fetchRelationshipPivot($type, $parent, $relation);
    }

    public function countRelated(
        string $type,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): array {
        return $this->inner->countRelated($type, $parents, $relation, $criteria, $request);
    }

    public function relatedToOneMatches(
        string $relatedType,
        object $related,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): bool {
        return $this->inner->relatedToOneMatches($relatedType, $related, $relation, $criteria, $request);
    }

    public function relatedToOneMatchesBatch(
        string $parentType,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): array {
        return $this->inner->relatedToOneMatchesBatch($parentType, $parents, $relation, $criteria, $request);
    }
}
