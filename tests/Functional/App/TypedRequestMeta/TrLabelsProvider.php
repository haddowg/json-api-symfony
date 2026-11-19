<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApiBundle\DataProvider\AbstractDataProvider;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * @extends AbstractDataProvider<object>
 */
final class TrLabelsProvider extends AbstractDataProvider
{
    public function supports(string $type): bool
    {
        return $type === 'tr-labels';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return self::all()[$id] ?? null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return (new InMemoryDataProvider($type, self::all(), static fn(object $label): string => $label instanceof TrLabel ? $label->id : ''))->fetchCollection($type, $criteria);
    }

    /**
     * @return array<string, TrLabel>
     */
    private static function all(): array
    {
        return ['urgent' => new TrLabel('urgent', 'Urgent')];
    }
}
