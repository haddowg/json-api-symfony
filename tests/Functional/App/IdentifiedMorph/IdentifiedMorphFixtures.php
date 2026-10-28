<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorph;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\HidingProvider;

/**
 * Ten campaigns whose `target` is a stored type and id pair:
 *
 *  - 1 and 7: advertiser 1; 5: advertiser 2; 9: advertiser 99, which doesn't exist;
 *  - 2 and 8: agency 1; 6 and 10: agency 3; 3: agency 2, which the scope hides;
 *  - 4: no target.
 *
 * Advertiser 1 and agency 1 are in region 1, the rest in region 2.
 */
final class IdentifiedMorphFixtures
{
    /**
     * @var array<string, InMemoryDataProvider>|null
     */
    private static ?array $providers = null;

    /**
     * @return array{campaigns: list<IdentifiedCampaign>, advertisers: list<IdentifiedAdvertiser>, agencies: list<IdentifiedAgency>, regions: list<IdentifiedRegion>}
     */
    public static function graph(): array
    {
        $north = new IdentifiedRegion(1, 'North');
        $south = new IdentifiedRegion(2, 'South');

        $pairs = [
            1 => ['advertisers', 1],
            2 => ['agencies', 1],
            3 => ['agencies', 2],
            4 => [null, null],
            5 => ['advertisers', 2],
            6 => ['agencies', 3],
            7 => ['advertisers', 1],
            8 => ['agencies', 1],
            9 => ['advertisers', 99],
            10 => ['agencies', 3],
        ];

        $campaigns = [];
        foreach ($pairs as $id => [$type, $targetId]) {
            $campaigns[] = new IdentifiedCampaign($id, 'Campaign ' . $id, $type, $targetId);
        }

        return [
            'campaigns' => $campaigns,
            'advertisers' => [
                new IdentifiedAdvertiser(1, 'Acme', $north),
                new IdentifiedAdvertiser(2, 'Globex', $south),
            ],
            'agencies' => [
                new IdentifiedAgency(1, 'Visible Agency', $north),
                new IdentifiedAgency(2, 'Hidden Agency', $south, hidden: true),
                new IdentifiedAgency(3, 'Third Agency', $south),
            ],
            'regions' => [$north, $south],
        ];
    }

    /**
     * One in-memory provider per type over a single shared graph.
     */
    public static function inMemoryProvider(string $type): InMemoryDataProvider
    {
        if (self::$providers === null) {
            self::$providers = [];
            foreach (self::graph() as $graphType => $items) {
                $byId = [];
                foreach ($items as $item) {
                    $byId[(string) $item->id] = $item;
                }
                self::$providers[$graphType] = new InMemoryDataProvider($graphType, $byId);
            }
        }

        return self::$providers[$type];
    }

    /**
     * The agencies, served through a provider that hides the hidden ones itself.
     */
    public static function hidingAgencies(): HidingProvider
    {
        return new HidingProvider(['agencies'], self::inMemoryProvider('agencies'));
    }

    public static function reset(): void
    {
        self::$providers = null;
    }
}
