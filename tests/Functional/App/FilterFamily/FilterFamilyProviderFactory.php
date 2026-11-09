<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

final class FilterFamilyProviderFactory
{
    public static function items(): InMemoryDataProvider
    {
        $items = [];
        foreach (self::seededItems() as $item) {
            $items[$item->id] = $item;
        }

        return new InMemoryDataProvider('ffItems', $items, static function (object $item): string {
            \assert($item instanceof Item);

            return $item->id;
        });
    }

    public static function boards(): InMemoryDataProvider
    {
        $boards = [];
        foreach (ItemFixtures::boards() as $id => $name) {
            $boards[(string) $id] = new Board((string) $id, $name);
        }
        foreach (self::seededItems() as $item) {
            $boards[(string) ItemFixtures::items()[(int) $item->id]['board']]->items[] = $item;
        }

        return new InMemoryDataProvider('ffBoards', $boards, static function (object $board): string {
            \assert($board instanceof Board);

            return $board->id;
        });
    }

    /**
     * @return list<Item>
     */
    private static function seededItems(): array
    {
        $items = [];
        foreach (ItemFixtures::items() as $id => $row) {
            $items[] = new Item((string) $id, $row['name'], $row['label'], $row['color'], $row['size'], $row['weight']);
        }

        return $items;
    }
}
