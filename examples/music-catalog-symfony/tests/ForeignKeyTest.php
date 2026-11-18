<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use haddowg\JsonApiBundle\Examples\MusicCatalog\Query\QueryCountingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The `stations` type is served by a custom provider with no database behind it, and its
 * `plays` relation reads the Doctrine `plays` whose `station` holds the station's id
 * (docs/relationships.md). KEXP played tracks 1 to 3 as plays 1 to 3, NTS track 4 as
 * play 4, and FIP nothing.
 */
#[Group('spec:fetching-relationships')]
final class ForeignKeyTest extends MusicCatalogKernelTestCase
{
    #[Test]
    public function aStationsRelatedEndpointServesThePlaysThatNameIt(): void
    {
        $plays = $this->decode($this->handle('/stations/kexp/plays'))['data'] ?? null;

        self::assertSame(['1', '2', '3'], $this->ids($plays));
        self::assertSame([], $this->ids($this->decode($this->handle('/stations/fip/plays'))['data'] ?? null));
    }

    #[Test]
    public function aStationsRelationshipEndpointServesTheSameLinkage(): void
    {
        self::assertSame(['4'], $this->ids($this->decode($this->handle('/stations/nts/relationships/plays'))['data'] ?? null));
    }

    #[Test]
    public function anIncludeReadsEveryStationsPlaysInOneQuery(): void
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);
        $logger->reset();

        $document = $this->decode($this->handle('/stations?include=plays'));

        $stations = $document['data'] ?? null;
        self::assertIsArray($stations);
        $linkage = [];
        foreach ($stations as $station) {
            self::assertIsArray($station);
            $relationships = $station['relationships'] ?? null;
            self::assertIsArray($relationships);
            $plays = $relationships['plays'] ?? null;
            self::assertIsArray($plays);
            self::assertIsString($station['id'] ?? null);
            $linkage[$station['id']] = $this->ids($plays['data'] ?? null);
        }
        self::assertSame(['kexp' => ['1', '2', '3'], 'nts' => ['4'], 'fip' => []], $linkage);
        self::assertSame(1, $logger->count());
    }

    /**
     * @return list<string>
     */
    private function ids(mixed $data): array
    {
        self::assertIsArray($data);
        $ids = [];
        foreach ($data as $item) {
            self::assertIsArray($item);
            self::assertIsString($item['id'] ?? null);
            $ids[] = $item['id'];
        }

        return $ids;
    }
}
