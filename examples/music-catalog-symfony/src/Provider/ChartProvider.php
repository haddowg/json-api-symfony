<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Provider;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\CollectionResult;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Model\Chart;

/**
 * The data half of the resource-less `charts` type (capability composition): a
 * tiny custom provider returning a fixed list — there is no Doctrine entity, so
 * the {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Serializer\ChartSerializer}
 * is paired with this provider rather than the Doctrine reference. It proves a
 * fetchable type needs only a serializer (for the wire shape) + a provider (for
 * the data), no resource/hydrator/persister at all.
 *
 * It delegates to an {@see InMemoryDataProvider}, so `GET /charts` /
 * `GET /charts/{id}` reuse the shared {@see \haddowg\JsonApiBundle\DataProvider\CriteriaApplier}
 * + array window — the same machinery the Doctrine and in-memory providers run, so
 * a non-DB source is a first-class collection. The chart fixture mirrors core's
 * in-memory seed so the two example apps render the same chart.
 *
 * @implements DataProviderInterface<object>
 */
final class ChartProvider implements DataProviderInterface
{
    private readonly InMemoryDataProvider $inner;

    public function __construct()
    {
        $this->inner = new InMemoryDataProvider('charts', [
            '1' => new Chart(
                id: '1',
                name: 'Weekly Top',
                period: '2024-W03',
                entries: [
                    ['rank' => 1, 'trackId' => '2', 'plays' => 12000],
                    ['rank' => 2, 'trackId' => '1', 'plays' => 9800],
                    ['rank' => 3, 'trackId' => '4', 'plays' => 7100],
                ],
            ),
        ]);
    }

    public function supports(string $type): bool
    {
        return $type === 'charts';
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

    public function fetchRelationshipPivot(string $type, object $parent, RelationInterface $relation): array
    {
        return $this->inner->fetchRelationshipPivot($type, $parent, $relation);
    }
}
