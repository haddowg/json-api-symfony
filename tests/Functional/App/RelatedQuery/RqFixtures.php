<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedQuery;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Ada mentors Grace, who mentors Alan. Ada wrote Alpha and Gamma, Grace wrote Beta,
 * and shelf 1 holds all three with Beta featured. The ids follow insertion order, as
 * the Doctrine database assigns them.
 */
final class RqFixtures
{
    /**
     * @var array<string, InMemoryDataProvider>|null
     */
    private static ?array $providers = null;

    /**
     * @return array{people: list<RqPerson>, books: list<RqBook>, shelves: list<RqShelf>}
     */
    public static function graph(bool $withIds = false): array
    {
        $ada = new RqPerson('Ada');
        $grace = new RqPerson('Grace', $ada);
        $alan = new RqPerson('Alan', $grace);

        $alpha = new RqBook('Alpha', $ada);
        $beta = new RqBook('Beta', $grace);
        $gamma = new RqBook('Gamma', $ada);

        $shelf = new RqShelf('Main');
        foreach ([$alpha, $beta, $gamma] as $book) {
            $shelf->books->add($book);
        }
        $shelf->featured = $beta;

        $graph = ['people' => [$ada, $grace, $alan], 'books' => [$alpha, $beta, $gamma], 'shelves' => [$shelf]];
        if ($withIds) {
            foreach ($graph as $entities) {
                foreach ($entities as $index => $entity) {
                    $entity->id = $index + 1;
                }
            }
        }

        return $graph;
    }

    /**
     * @return InMemoryDataProvider
     */
    public static function provider(string $type): InMemoryDataProvider
    {
        return self::providers()[$type];
    }

    public static function persister(string $type): InMemoryDataPersister
    {
        $providers = self::providers();

        return new InMemoryDataPersister(
            $type,
            $providers[$type]->store(),
            static fn(): object => match ($type) {
                'shelves' => new RqShelf(),
                'books' => new RqBook(),
                default => new RqPerson(),
            },
            static fn(string $related, string $id): ?object => isset($providers[$related]) ? $providers[$related]->store()->find($id) : null,
        );
    }

    public static function reset(): void
    {
        self::$providers = null;
    }

    /**
     * @return array<string, InMemoryDataProvider>
     */
    private static function providers(): array
    {
        if (self::$providers === null) {
            self::$providers = [];
            foreach (self::graph(withIds: true) as $type => $entities) {
                $byId = [];
                foreach ($entities as $entity) {
                    $byId[(string) $entity->id] = $entity;
                }
                self::$providers[$type] = new InMemoryDataProvider(
                    $type,
                    $byId,
                    static fn(object $item): string => \property_exists($item, 'id') && $item->id !== null ? (string) $item->id : '',
                );
            }
        }

        return self::$providers;
    }
}
