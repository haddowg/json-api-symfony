<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

/**
 * The shared seed. Ada owns items 1 to 5 (One to Five), Bob item 6 and Cy none, and item
 * 7 has no owner. Ada has notes 1 and 2, the second archived, and Bob note 3. Ada has
 * profile 1, Bob none, and profile 2 has no owner. A note's owner can't be cleared.
 * Ada has secrets 1 and 2, the second hidden. Team 1 has members 1 to 3, team 2 member 4.
 */
final class ForeignKeySeed
{
    /**
     * @return array{items: list<FkItem>, notes: list<FkNote>, profiles: list<FkProfile>, secrets: list<FkSecret>, teams: list<FkTeam>, members: list<FkMember>}
     */
    public static function build(): array
    {
        $items = [];
        foreach ([1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five'] as $id => $title) {
            $items[] = new FkItem($id, $title, 'ada');
        }
        $items[] = new FkItem(6, 'Six', 'bob');
        $items[] = new FkItem(7, 'Seven');

        $teams = [new FkTeam(1, 'Red'), new FkTeam(2, 'Blue')];
        $members = [new FkMember(1, 'Ann', $teams[0]), new FkMember(2, 'Ben', $teams[0]), new FkMember(3, 'Cat', $teams[0]), new FkMember(4, 'Dan', $teams[1])];

        return [
            'items' => $items,
            'notes' => [new FkNote(1, 'Hello', 'ada'), new FkNote(2, 'Old', 'ada', archived: true), new FkNote(3, 'Hi', 'bob')],
            'profiles' => [new FkProfile(1, 'Mathematician', 'ada'), new FkProfile(2, 'Poet')],
            'secrets' => [new FkSecret(1, 'Shown', 'ada'), new FkSecret(2, 'Hidden', 'ada', hidden: true)],
            'teams' => $teams,
            'members' => $members,
        ];
    }
}
