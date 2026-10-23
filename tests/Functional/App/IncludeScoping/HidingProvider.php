<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;
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
        return $this->visible($this->inner->fetchCollection($type, $criteria));
    }

    public function fetchRelatedCollection(string $relatedType, object $parent, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): CollectionResult
    {
        return $this->visible($this->inner->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request));
    }

    public function fetchRelatedCollectionBatch(string $parentType, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): RelatedBatch
    {
        $results = [];
        foreach ($parents as $parent) {
            $id = \property_exists($parent, 'id') && \is_int($parent->id) ? (string) $parent->id : null;
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
        return $this->inner->countRelated($type, $parents, $relation, $criteria, $request);
    }

    public function relatedToOneMatches(string $relatedType, object $related, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): bool
    {
        return !self::hidden($related) && $this->inner->relatedToOneMatches($relatedType, $related, $relation, $criteria, $request);
    }

    public function relatedToOneMatchesBatch(string $parentType, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): array
    {
        return $this->inner->relatedToOneMatchesBatch($parentType, $parents, $relation, $criteria, $request);
    }

    public function fetchRelationshipPivot(string $type, object $parent, RelationInterface $relation): array
    {
        return $this->inner->fetchRelationshipPivot($type, $parent, $relation);
    }

    /**
     * @param CollectionResult<object> $result
     *
     * @return CollectionResult<object>
     */
    private function visible(CollectionResult $result): CollectionResult
    {
        $items = \is_array($result->items) ? $result->items : \iterator_to_array($result->items, false);

        return new CollectionResult(\array_values(\array_filter($items, static fn(object $item): bool => !self::hidden($item))));
    }

    private static function hidden(object $item): bool
    {
        return \property_exists($item, 'hidden') && $item->hidden === true;
    }
}
