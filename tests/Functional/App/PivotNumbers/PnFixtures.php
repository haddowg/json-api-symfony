<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotNumbers;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Three cards and board 1, which links and pins card 1 with a count of 1 and a weight of
 * 1.5.
 */
final class PnFixtures
{
    /**
     * @var array<string, InMemoryDataProvider>|null
     */
    private ?array $providers = null;

    /**
     * @return array{cards: list<PnCard>, boards: list<PnBoard>}
     */
    public static function graph(bool $pins): array
    {
        $cards = [new PnCard('1', 'One'), new PnCard('2', 'Two'), new PnCard('3', 'Three')];
        $board = new PnBoard('1', 'Main');
        $board->links->add(new PnLink(null, $board, 'pn-cards', '1', 1, 1.5));
        if ($pins) {
            $board->pins->add(new PnPin(null, $board, $cards[0], 1, 1.5));
        }

        return ['cards' => $cards, 'boards' => [$board]];
    }

    public function provider(string $type): InMemoryDataProvider
    {
        if ($this->providers === null) {
            $graph = self::graph(false);
            $identify = static fn(object $item): string => $item instanceof PnBoard || $item instanceof PnCard ? (string) $item->id : '';
            $this->providers = [];
            foreach (['pn-boards' => $graph['boards'], 'pn-cards' => $graph['cards']] as $name => $items) {
                $byId = [];
                foreach ($items as $item) {
                    $byId[(string) $item->id] = $item;
                }
                $this->providers[$name] = new InMemoryDataProvider($name, $byId, $identify);
            }
        }

        return $this->providers[$type];
    }

    public function persister(string $type): InMemoryDataPersister
    {
        return new InMemoryDataPersister(
            $type,
            $this->provider($type)->store(),
            static fn(): object => $type === 'pn-boards' ? new PnBoard() : new PnCard(),
            fn(string $relatedType, string $id): ?object => $this->provider($relatedType)->store()->find($id),
        );
    }
}
