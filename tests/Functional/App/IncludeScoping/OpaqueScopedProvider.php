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

/**
 * A custom provider that does not say whether its related reads are scoped (so the
 * bundle assumes they are) and cannot batch any relation.
 *
 * @implements DataProviderInterface<object>
 */
final class OpaqueScopedProvider implements DataProviderInterface
{
    public function __construct(private readonly InMemoryDataProvider $inner) {}

    public static function create(string $type, string $base): self
    {
        $byId = [];
        foreach (ScopingFixtures::graph()[$base] as $item) {
            $byId[(string) $item->id] = $item;
        }

        return new self(new InMemoryDataProvider($type, $byId, static fn(object $item): string => \property_exists($item, 'id') && \is_int($item->id) ? (string) $item->id : ''));
    }

    public function supports(string $type): bool
    {
        return $this->inner->supports($type);
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return $this->inner->fetchOne($type, $id);
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return $this->inner->fetchCollection($type, $criteria);
    }

    public function fetchRelatedCollection(string $relatedType, object $parent, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): CollectionResult
    {
        return $this->inner->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request);
    }

    public function fetchRelatedCollectionBatch(string $parentType, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): RelatedBatch
    {
        return RelatedBatch::unsupported();
    }

    public function countRelated(string $type, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): array
    {
        return $this->inner->countRelated($type, $parents, $relation, $criteria, $request);
    }

    public function relatedToOneMatches(string $relatedType, object $related, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): bool
    {
        return $this->inner->relatedToOneMatches($relatedType, $related, $relation, $criteria, $request);
    }

    public function relatedToOneMatchesBatch(string $parentType, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): array
    {
        return $this->inner->relatedToOneMatchesBatch($parentType, $parents, $relation, $criteria, $request);
    }

    public function fetchRelationshipPivot(string $type, object $parent, RelationInterface $relation): array
    {
        return $this->inner->fetchRelationshipPivot($type, $parent, $relation);
    }
}
