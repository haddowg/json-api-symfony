<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the writable in-memory `badges` / `medals` graph for the
 * request-aware-predicates conformance suite (bundle ADR 0084): two providers over
 * one seeded object graph (so a parent fetch returns the related objects), plus an
 * {@see InMemoryDataPersister} for `badges` whose related-object resolver looks a
 * medal id up in the medals store — so a relationship mutation can resolve a
 * linkage id back to the stored {@see Medal} and set it on the badge's `medals`
 * collection.
 *
 * Seed: badge 1 ("First", rank "bronze", secret "topsecret", clearance "secret")
 * holding medal 1; medals 1-3 ("Gold"/"Silver"/"Bronze"). The providers are built
 * once and shared via the static graph so the persister's resolver reads the same
 * stores the read providers serve; {@see reset()} drops them so each test boots a
 * fresh, unmutated graph.
 */
final class RequestAwarePredicatesFactory
{
    private static ?InMemoryDataProvider $badges = null;

    private static ?InMemoryDataProvider $medals = null;

    public static function createBadges(): InMemoryDataProvider
    {
        return self::badges();
    }

    public static function createMedals(): InMemoryDataProvider
    {
        return self::medals();
    }

    public static function createBadgesPersister(): InMemoryDataPersister
    {
        $medals = self::medals();

        return new InMemoryDataPersister(
            'badges',
            self::badges()->store(),
            static fn(): Badge => new Badge(),
            static function (string $type, string $id) use ($medals): ?object {
                return $type === 'medals' ? $medals->store()->find($id) : null;
            },
        );
    }

    /**
     * Resets the per-kernel singletons so each test boots a fresh, unmutated graph.
     */
    public static function reset(): void
    {
        self::$badges = null;
        self::$medals = null;
    }

    private static function badges(): InMemoryDataProvider
    {
        return self::$badges ??= self::buildBadges();
    }

    private static function medals(): InMemoryDataProvider
    {
        self::$medals ??= self::buildMedals();

        return self::$medals;
    }

    private static function buildBadges(): InMemoryDataProvider
    {
        $medals = self::medals();
        $first = $medals->store()->find('1');
        \assert($first instanceof Medal);

        $badge = new Badge(1, 'First', 'topsecret', null, 'bronze', 'secret', [$first]);

        // Wire the inverse `medals -> badges` back-reference so a badge is reachable
        // as an included/related resource off medal 1 (mirroring the Doctrine
        // bidirectional ManyToMany), letting the hidden-`secret` negative assertion
        // exercise the included and related serialization paths too.
        $first->badges = [$badge];

        $badges = ['1' => $badge];

        return new InMemoryDataProvider(
            'badges',
            $badges,
            static function (object $item): string {
                \assert($item instanceof Badge);

                return $item->id === null ? '' : (string) $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Badge);

                $item->id = (int) $id;
            },
        );
    }

    private static function buildMedals(): InMemoryDataProvider
    {
        $medals = [
            '1' => new Medal(1, 'Gold'),
            '2' => new Medal(2, 'Silver'),
            '3' => new Medal(3, 'Bronze'),
        ];

        return new InMemoryDataProvider(
            'medals',
            $medals,
            static function (object $item): string {
                \assert($item instanceof Medal);

                return $item->id === null ? '' : (string) $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Medal);

                $item->id = (int) $id;
            },
        );
    }
}
