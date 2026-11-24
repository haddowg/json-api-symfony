<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\PrefixIdEncoder;

/**
 * One in-memory provider per type over its slice of {@see MorphKeysFixtures::graph()},
 * keyed by wire id and leaving out hidden rows, as the Doctrine extension does.
 */
final class MorphKeysProviderFactory
{
    /**
     * @var array<string, list<object>>|null
     */
    private static ?array $graph = null;

    public static function provider(string $type, string $slice, string $prefix = ''): InMemoryDataProvider
    {
        self::$graph ??= MorphKeysFixtures::graph();
        $encoder = $prefix !== '' ? new PrefixIdEncoder($prefix) : null;

        $items = [];
        foreach (self::$graph[$slice] ?? [] as $item) {
            if (!\property_exists($item, 'hidden') || $item->hidden !== true) {
                $items[self::idOf($item, $encoder)] = $item;
            }
        }

        return new InMemoryDataProvider($type, $items, static fn(object $item): string => self::idOf($item, $encoder));
    }

    public static function scoped(string $type, string $slice, string $prefix = ''): HiddenTargetsProvider
    {
        return new HiddenTargetsProvider($type, self::provider($type, $slice, $prefix));
    }

    private static function idOf(object $item, ?IdEncoderInterface $encoder): string
    {
        $id = \property_exists($item, 'id') ? $item->id : null;
        $key = \is_scalar($id) ? (string) $id : '';

        return $encoder !== null ? $encoder->encode($id) : $key;
    }
}
