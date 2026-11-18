<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedMeta;

/**
 * Board 1 holds cards 1 to 3, card 2 done and card 3 pinned.
 */
final class TypedMetaSeed
{
    /**
     * @return array{boards: list<TmBoard>, cards: list<TmCard>}
     */
    public static function build(): array
    {
        $board = new TmBoard();
        $board->id = 1;
        $board->name = 'Sprint';

        $cards = [];
        foreach ([1 => ['Plan', false], 2 => ['Build', true], 3 => ['Ship', false]] as $id => [$title, $done]) {
            $card = new TmCard();
            $card->id = $id;
            $card->title = $title;
            $card->done = $done;
            $card->board = $board;
            $board->cards->add($card);
            $cards[] = $card;
        }
        $board->pinned = $cards[2];

        return ['boards' => [$board], 'cards' => $cards];
    }
}
