<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Through;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataProvider\AbstractDataProvider;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\Through\Doctrine\ThrAdvertiserEntity;

/**
 * Serves the visible advertisers only, scoping every read that reaches the type, as a
 * custom scoped provider does.
 *
 * @extends AbstractDataProvider<object>
 */
final class ScopedThrAdvertiserProvider extends AbstractDataProvider
{
    /**
     * @param list<ThrAdvertiserEntity> $advertisers
     */
    public function __construct(private readonly array $advertisers) {}

    public function supports(string $type): bool
    {
        return $type === 'thrAdvertisers';
    }

    public function scopesRelatedReads(RelationInterface $relation): bool
    {
        return true;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        foreach ($this->visible() as $advertiser) {
            if ((string) $advertiser->id === $id) {
                return $advertiser;
            }
        }

        return null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return (new InMemoryDataProvider('thrAdvertisers', $this->visible()))->fetchCollection('thrAdvertisers', $criteria);
    }

    /**
     * @return list<ThrAdvertiserEntity>
     */
    private function visible(): array
    {
        return \array_values(\array_filter($this->advertisers, static fn(ThrAdvertiserEntity $advertiser): bool => !$advertiser->hidden));
    }
}
