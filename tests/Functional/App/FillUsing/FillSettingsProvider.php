<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FillUsing;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApiBundle\DataProvider\AbstractDataProvider;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Serves `fill-settings` from a fixed list, with no scope and no batched read, so an id
 * it does not hold is only ever found missing through `fetchOne()`.
 *
 * @extends AbstractDataProvider<object>
 */
final class FillSettingsProvider extends AbstractDataProvider
{
    public function supports(string $type): bool
    {
        return $type === FillSettingResource::$type;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return self::all()[$id] ?? null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return (new InMemoryDataProvider($type, self::all()))->fetchCollection($type, $criteria);
    }

    /**
     * @return array<int|string, FillSetting>
     */
    private static function all(): array
    {
        return ['1' => new FillSetting('1', 'Compact'), '2' => new FillSetting('2', 'Wide')];
    }
}
