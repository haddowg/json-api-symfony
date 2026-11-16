<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FirstPage;

/**
 * The shared seed. Board 1 holds cards 1 to 5 and labels 1 to 3, board 2 holds card 6
 * and label 2. The desk holds cards 1 to 5, updated so that newest first they run 3, 5,
 * 1, 4, 2. Card 1 has comments 1 to 3, card 2 comment 4 and card 3 comments 5 and 6.
 */
final class FirstPageSeed
{
    /**
     * @return array{desks: list<FpDesk>, boards: list<FpBoard>, cards: list<FpCard>, comments: list<FpComment>, labels: list<FpLabel>}
     */
    public static function build(): array
    {
        $desk = new FpDesk(1, 'Front desk');
        $roadmap = new FpBoard(1, 'Roadmap');
        $backlog = new FpBoard(2, 'Backlog');

        $labels = [new FpLabel(1, 'red'), new FpLabel(2, 'green'), new FpLabel(3, 'blue')];
        foreach ($labels as $label) {
            $roadmap->labels->add($label);
        }
        $backlog->labels->add($labels[1]);

        $cards = [];
        foreach ([1 => ['One', '03'], 2 => ['Two', '01'], 3 => ['Three', '05'], 4 => ['Four', '02'], 5 => ['Five', '04']] as $id => [$title, $day]) {
            $cards[] = new FpCard($id, $title, new \DateTimeImmutable('2026-01-' . $day . 'T00:00:00+00:00'), $roadmap, $desk);
        }
        $cards[] = new FpCard(6, 'Six', new \DateTimeImmutable('2026-01-06T00:00:00+00:00'), $backlog);

        $comments = [];
        foreach ([1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 2, 6 => 2] as $id => $card) {
            $comments[] = new FpComment($id, 'Comment ' . $id, $cards[$card]);
        }

        return ['desks' => [$desk], 'boards' => [$roadmap, $backlog], 'cards' => $cards, 'comments' => $comments, 'labels' => $labels];
    }
}
