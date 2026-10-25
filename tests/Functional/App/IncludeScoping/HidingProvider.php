<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\DataProvider\RelatedBatch;
use haddowg\JsonApiBundle\DataProvider\ScopesRelatedReadsInterface;

/**
 * An application provider for the related types that hides soft-hidden rows itself,
 * wrapping the Doctrine or in-memory provider that holds them. The parent type stays
 * on its own provider, so only this one knows the scope.
 *
 * @implements DataProviderInterface<object>
 */
final class HidingProvider implements DataProviderInterface, ScopesRelatedReadsInterface
{
    /**
     * @param list<string>                  $types
     * @param DataProviderInterface<object> $inner
     */
    public function __construct(
        private readonly array $types,
        private readonly DataProviderInterface $inner,
    ) {}

    public static function inMemory(string $type): self
    {
        return new self([$type], ScopingFixtures::inMemoryProvider($type));
    }

    public function supports(string $type): bool
    {
        return \in_array($type, $this->types, true);
    }

    public function scopesRelatedReads(RelationInterface $relation): bool
    {
        return true;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        $item = $this->inner->fetchOne($type, $id);

        return $item !== null && !self::hidden($item) ? $item : null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return $this->page($type, $this->inner->fetchCollection($type, self::unpaged($criteria)), $criteria);
    }

    public function fetchRelatedCollection(string $relatedType, object $parent, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): CollectionResult
    {
        return $this->page($relatedType, $this->inner->fetchRelatedCollection($relatedType, $parent, $relation, self::unpaged($criteria), $request), $criteria);
    }

    public function fetchRelatedCollectionBatch(string $parentType, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): RelatedBatch
    {
        $results = [];
        foreach ($parents as $parent) {
            $id = self::idOf($parent);
            if ($id === null) {
                continue;
            }

            if ($relation->isToMany()) {
                $results[$id] = $this->fetchRelatedCollection($relation->relatedTypes()[0] ?? $parentType, $parent, $relation, $criteria, $request);

                continue;
            }

            $target = $relation->readValue($parent, $request);
            $results[$id] = new CollectionResult(\is_object($target) && !self::hidden($target) ? [$target] : []);
        }

        return new RelatedBatch($results);
    }

    public function countRelated(string $type, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): array
    {
        $relatedType = $relation->relatedTypes()[0] ?? $type;
        $counts = [];
        foreach ($parents as $parent) {
            $id = self::idOf($parent);
            if ($id === null) {
                continue;
            }
            $items = $this->fetchRelatedCollection($relatedType, $parent, $relation, self::unpaged($criteria), $request)->items;
            $counts[$id] = \is_array($items) ? \count($items) : \iterator_count($items);
        }

        return $counts;
    }

    public function relatedToOneMatches(string $relatedType, object $related, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): bool
    {
        return !self::hidden($related) && $this->inner->relatedToOneMatches($relatedType, $related, $relation, $criteria, $request);
    }

    public function relatedToOneMatchesBatch(string $parentType, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): array
    {
        $matches = $this->inner->relatedToOneMatchesBatch($parentType, $parents, $relation, $criteria, $request);
        foreach ($parents as $parent) {
            $id = self::idOf($parent);
            $target = $relation->readValue($parent, $request);
            if ($id !== null && \is_object($target) && self::hidden($target)) {
                $matches[$id] = false;
            }
        }

        return $matches;
    }

    public function fetchRelationshipPivot(string $type, object $parent, RelationInterface $relation): array
    {
        return $this->inner->fetchRelationshipPivot($type, $parent, $relation);
    }

    /**
     * The visible rows of an unpaged result, run through `$criteria` so the page is full
     * and any count is of the visible rows.
     *
     * @param CollectionResult<object> $result
     *
     * @return CollectionResult<object>
     */
    private function page(string $type, CollectionResult $result, CollectionCriteria $criteria): CollectionResult
    {
        $items = \is_array($result->items) ? $result->items : \iterator_to_array($result->items, false);
        $visible = \array_values(\array_filter($items, static fn(object $item): bool => !self::hidden($item)));

        return (new InMemoryDataProvider($type, $visible))->fetchCollection($type, $criteria);
    }

    /**
     * `$criteria` without its page or count, so the scope runs before any paging.
     */
    private static function unpaged(CollectionCriteria $criteria): CollectionCriteria
    {
        return new CollectionCriteria($criteria->queryParameters, $criteria->filters, $criteria->sorts, null, $criteria->defaultSort, $criteria->aliasOf);
    }

    private static function idOf(object $parent): ?string
    {
        return \property_exists($parent, 'id') && \is_int($parent->id) ? (string) $parent->id : null;
    }

    private static function hidden(object $item): bool
    {
        return \property_exists($item, 'hidden') && $item->hidden === true;
    }
}
