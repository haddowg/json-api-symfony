<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys;

use haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\Doctrine\MkBadgeEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\Doctrine\MkCounterEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\Doctrine\MkNoteEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\Doctrine\MkPinEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\Doctrine\MkTokenEntity;

/**
 * The rows both kernels serve. Counters 2, and badges 3 and 4, are hidden. Each note's
 * stored id is the target's storage key or its wire id:
 *
 * | note | subject            | pins                  |
 * |------|--------------------|-----------------------|
 * | 1    | mkCounters 1       | mkBadges b-2          |
 * | 2    | mkCounters 2       | mkBadges 3            |
 * | 3    | mkBadges 1         | mkCounters 1          |
 * | 4    | mkBadges b-2       | mkBadges 1            |
 * | 5    | mkBadges b-3       |                       |
 * | 6    | mkBadges 4         |                       |
 * | 7    | mkTokens 1         | mkTokens t-2          |
 * | 8    | mkTokens t-2       |                       |
 * | 9    |                    |                       |
 */
final class MorphKeysFixtures
{
    /**
     * @return array<string, list<object>>
     */
    public static function graph(): array
    {
        $notes = [
            new MkNoteEntity(1, 'mkCounters', '1'),
            new MkNoteEntity(2, 'mkCounters', '2'),
            new MkNoteEntity(3, 'mkBadges', '1'),
            new MkNoteEntity(4, 'mkBadges', 'b-2'),
            new MkNoteEntity(5, 'mkBadges', 'b-3'),
            new MkNoteEntity(6, 'mkBadges', '4'),
            new MkNoteEntity(7, 'mkTokens', '1'),
            new MkNoteEntity(8, 'mkTokens', 't-2'),
            new MkNoteEntity(9),
        ];

        return [
            'notes' => $notes,
            'pins' => [
                new MkPinEntity(1, $notes[0], 'mkBadges', 'b-2'),
                new MkPinEntity(2, $notes[1], 'mkBadges', '3'),
                new MkPinEntity(3, $notes[2], 'mkCounters', '1'),
                new MkPinEntity(4, $notes[3], 'mkBadges', '1'),
                new MkPinEntity(5, $notes[6], 'mkTokens', 't-2'),
            ],
            'counters' => [new MkCounterEntity(1, 'one'), new MkCounterEntity(2, 'two', true)],
            'badges' => [new MkBadgeEntity(1, 'gold'), new MkBadgeEntity(2, 'silver'), new MkBadgeEntity(3, 'bronze', true), new MkBadgeEntity(4, 'tin', true)],
            'tokens' => [new MkTokenEntity(1, 'red'), new MkTokenEntity(2, 'blue')],
        ];
    }
}
