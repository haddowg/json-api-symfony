<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataProvider\AbstractDataProvider;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Serves a type's visible rows only and says it scopes them, so its related reads and the
 * targets a WhereMorph matches go through it, as the Doctrine extension's do.
 *
 * @extends AbstractDataProvider<object>
 */
final class HiddenTargetsProvider extends AbstractDataProvider
{
    public function __construct(private readonly string $type, private readonly InMemoryDataProvider $visible) {}

    public function supports(string $type): bool
    {
        return $type === $this->type;
    }

    public function scopesRelatedReads(RelationInterface $relation): bool
    {
        return true;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return $this->visible->fetchOne($type, $id);
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return $this->visible->fetchCollection($type, $criteria);
    }
}
