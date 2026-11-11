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
 * Serves a run's notes one run at a time: its batch is the base class's unsupported
 * answer.
 *
 * @extends AbstractDataProvider<object>
 */
final class RunNotesProvider extends AbstractDataProvider
{
    public function supports(string $type): bool
    {
        return $type === 'runNotes';
    }

    public function scopesRelatedReads(RelationInterface $relation): bool
    {
        return true;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        foreach (RunFixtures::notes() as $note) {
            if ((string) $note->id === $id) {
                return $note;
            }
        }

        return null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return (new InMemoryDataProvider($type, RunFixtures::notes()))->fetchCollection($type, $criteria);
    }

    public function fetchRelatedCollection(string $relatedType, object $parent, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): CollectionResult
    {
        ProviderCalls::record('related', $relatedType);
        $runId = \property_exists($parent, 'id') ? $parent->id : null;
        $notes = \array_values(\array_filter(RunFixtures::notes(), static fn(RunNote $note): bool => $note->runId === $runId));

        return (new InMemoryDataProvider($relatedType, $notes))->fetchCollection($relatedType, $criteria);
    }

    public function fetchRelatedCollectionBatch(string $parentType, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): RelatedBatch
    {
        ProviderCalls::record('batch', $relation->relatedTypes()[0] ?? '');

        return parent::fetchRelatedCollectionBatch($parentType, $parents, $relation, $criteria, $request);
    }
}
