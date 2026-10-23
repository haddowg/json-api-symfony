<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * The include-scoping graph. Agency 2 ("Soft Hidden") and member 2 are soft-hidden.
 *
 *  - advertiser 1: agencies 1 and 2, placed with agency 1 ("Lead") and agency 2
 *    ("Secret Role");
 *  - advertiser 2: agencies 2 and 3, placed with agency 2 ("Secret Role") and
 *    agency 3 ("Support");
 *  - agency 1: members 1 and 2, led by member 2; agency 2: member 4; agency 3:
 *    member 3, led by member 3.
 */
final class ScopingFixtures
{
    /**
     * @return array{advertisers: list<ScopingAdvertiser>, agencies: list<ScopingAgency>, members: list<ScopingMember>, placements: list<ScopingPlacement>}
     */
    public static function graph(): array
    {
        $agency1 = new ScopingAgency(1, 'Visible One');
        $agency2 = new ScopingAgency(2, 'Soft Hidden', hidden: true);
        $agency3 = new ScopingAgency(3, 'Visible Three');

        $members = [
            new ScopingMember(1, 'Member One', $agency1),
            new ScopingMember(2, 'Hidden Member', $agency1, hidden: true),
            new ScopingMember(3, 'Member Three', $agency3),
            new ScopingMember(4, 'Member Of Hidden', $agency2),
        ];

        // Agency 1 is led by the hidden member 2, agency 3 by member 3.
        $members[1]->lead($agency1);
        $members[2]->lead($agency3);

        $advertiser1 = new ScopingAdvertiser(1, 'Advertiser One', [$agency1, $agency2]);
        $advertiser2 = new ScopingAdvertiser(2, 'Advertiser Two', [$agency2, $agency3]);

        return [
            'advertisers' => [$advertiser1, $advertiser2],
            'agencies' => [$agency1, $agency2, $agency3],
            'members' => $members,
            'placements' => [
                new ScopingPlacement(1, $advertiser1, $agency1, 'Lead'),
                new ScopingPlacement(2, $advertiser1, $agency2, 'Secret Role'),
                new ScopingPlacement(3, $advertiser2, $agency2, 'Secret Role'),
                new ScopingPlacement(4, $advertiser2, $agency3, 'Support'),
            ],
        ];
    }

    /**
     * One in-memory provider per type over a single shared graph, so the parents'
     * collections hold the very objects the related providers serve.
     *
     * @return array<string, InMemoryDataProvider>
     */
    public static function inMemoryProviders(): array
    {
        static $providers = null;
        if ($providers !== null) {
            return $providers;
        }

        $graph = self::graph();
        $identify = static fn(object $item): string => \property_exists($item, 'id') && \is_int($item->id) ? (string) $item->id : '';

        $providers = [];
        foreach (['' => '', 'partner-' => 'partner-'] as $prefix) {
            foreach (['advertisers', 'agencies', 'members'] as $base) {
                $items = $graph[$base];
                $byId = [];
                foreach ($items as $item) {
                    $byId[(string) $item->id] = $item;
                }
                $providers[$prefix . $base] = new InMemoryDataProvider($prefix . $base, $byId, $identify);
            }
        }

        return $providers;
    }

    public static function inMemoryPersister(string $type): InMemoryDataPersister
    {
        return new InMemoryDataPersister($type, self::inMemoryProvider($type)->store(), static fn(): ScopingMember => new ScopingMember(0, ''));
    }

    public static function inMemoryProvider(string $type): InMemoryDataProvider
    {
        return self::inMemoryProviders()[$type];
    }
}
