<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedProviderBatch;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataProvider\AbstractDataProvider;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\DataProvider\RelatedBatch;

/**
 * Serves a run's progress and advertisers, which the run doesn't hold, and answers a
 * whole page of runs in one batch, as a grouped query would.
 *
 * @extends AbstractDataProvider<object>
 */
final class RunReadsProvider extends AbstractDataProvider
{
    public function supports(string $type): bool
    {
        return $type === 'runProgress' || $type === 'runAdvertisers';
    }

    public function scopesRelatedReads(RelationInterface $relation): bool
    {
        return true;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        foreach (self::rows($type) as $row) {
            if ((string) $row->id === $id) {
                return $row;
            }
        }

        return null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return (new InMemoryDataProvider($type, self::rows($type)))->fetchCollection($type, $criteria);
    }

    public function fetchRelatedCollection(string $relatedType, object $parent, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): CollectionResult
    {
        ProviderCalls::record('related', $relatedType);

        return (new InMemoryDataProvider($relatedType, self::of($relatedType, self::runId($parent))))->fetchCollection($relatedType, $criteria);
    }

    public function fetchRelatedCollectionBatch(string $parentType, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): RelatedBatch
    {
        $relatedType = $relation->relatedTypes()[0] ?? '';
        ProviderCalls::record('batch', $relatedType);
        if ($criteria->queryParameters->filter !== []) {
            ProviderCalls::record('filtered batch', $relatedType);
        }

        $results = [];
        foreach ($parents as $parent) {
            $runId = self::runId($parent);
            $results[(string) $runId] = (new InMemoryDataProvider($relatedType, self::of($relatedType, $runId)))->fetchCollection($relatedType, $criteria);
        }

        return new RelatedBatch($results);
    }

    public function relatedToOneMatches(string $relatedType, object $related, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): bool
    {
        return (new InMemoryDataProvider($relatedType, [$related]))->relatedToOneMatches($relatedType, $related, $relation, $criteria, $request);
    }

    /**
     * @return list<RunProgress|RunAdvertiser>
     */
    private static function of(string $type, int $runId): array
    {
        return \array_values(\array_filter(self::rows($type), static fn(RunProgress|RunAdvertiser $row): bool => $row->runId === $runId));
    }

    /**
     * @return list<RunProgress|RunAdvertiser>
     */
    private static function rows(string $type): array
    {
        return $type === 'runProgress' ? RunFixtures::progress() : RunFixtures::advertisers();
    }

    private static function runId(object $parent): int
    {
        return \property_exists($parent, 'id') && \is_int($parent->id) ? $parent->id : 0;
    }
}
