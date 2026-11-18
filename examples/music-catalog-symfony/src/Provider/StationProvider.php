<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Provider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\WindowExecutor;
use haddowg\JsonApiBundle\DataProvider\AbstractDataProvider;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Model\Station;

/**
 * Serves the `stations` type from a fixed list, with no database behind it. Only the
 * three read abstracts are written: a station's plays are Doctrine rows, and its `plays`
 * relation names their `station` field as its foreign key, so the Doctrine provider reads
 * them for the whole page of stations at once. This provider keeps every related-read
 * default of {@see AbstractDataProvider}.
 *
 * @extends AbstractDataProvider<Station>
 */
final class StationProvider extends AbstractDataProvider
{
    public function supports(string $type): bool
    {
        return $type === 'stations';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        foreach (self::stations() as $station) {
            if ($station->id === $id) {
                return $station;
            }
        }

        return null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        $stations = self::stations();

        return (new WindowExecutor())->run(
            $criteria->window,
            countable: $criteria->wantsCount,
            all: static fn(): array => $stations,
            count: static fn(): int => \count($stations),
            page: static fn(int $offset, int $limit): array => \array_slice($stations, $offset, $limit),
            probe: static fn(int $offset, int $limit): array => \array_slice($stations, $offset, $limit),
        );
    }

    /**
     * @return list<Station>
     */
    private static function stations(): array
    {
        return [new Station('kexp', 'KEXP'), new Station('nts', 'NTS Radio'), new Station('fip', 'FIP')];
    }
}
