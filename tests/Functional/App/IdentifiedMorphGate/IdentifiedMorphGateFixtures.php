<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphGate;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\HidingProvider;

/**
 * Six posts whose `subject` is a stored type and id pair:
 *
 *  - 1: article 1, readable; 2: article 2, named `restricted`, which the article gate refuses;
 *  - 3: video 1, readable; 4: video 2, named `classified`, which the video gate refuses;
 *  - 5: video 3, named `restricted`, which only the article gate would refuse;
 *  - 6: no subject.
 *
 * Videos 1 and 3 are on channel 2, which the scope hides.
 */
final class IdentifiedMorphGateFixtures
{
    /**
     * @var array<string, InMemoryDataProvider>|null
     */
    private static ?array $providers = null;

    /**
     * @return array{gatedChannels: list<GatedChannel>, gatedPosts: list<GatedPost>, gatedArticles: list<GatedArticle>, gatedVideos: list<GatedVideo>}
     */
    public static function graph(): array
    {
        $hidden = new GatedChannel(2, 'Hidden Channel', hidden: true);

        return [
            'gatedChannels' => [new GatedChannel(1, 'Open Channel'), $hidden],
            'gatedPosts' => [
                new GatedPost(1, 'Post 1', 'gatedArticles', 1),
                new GatedPost(2, 'Post 2', 'gatedArticles', 2),
                new GatedPost(3, 'Post 3', 'gatedVideos', 1),
                new GatedPost(4, 'Post 4', 'gatedVideos', 2),
                new GatedPost(5, 'Post 5', 'gatedVideos', 3),
                new GatedPost(6, 'Post 6'),
            ],
            'gatedArticles' => [
                new GatedArticle(1, 'Open Article'),
                new GatedArticle(2, 'restricted'),
            ],
            'gatedVideos' => [
                new GatedVideo(1, 'Open Video', $hidden),
                new GatedVideo(2, 'classified'),
                new GatedVideo(3, 'restricted', $hidden),
            ],
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
     * The channels, served through a provider that hides the hidden ones itself.
     */
    public static function hidingChannels(): HidingProvider
    {
        return new HidingProvider(['gatedChannels'], self::inMemoryProvider('gatedChannels'));
    }

    public static function reset(): void
    {
        self::$providers = null;
    }
}
