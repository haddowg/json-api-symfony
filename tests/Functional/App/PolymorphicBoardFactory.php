<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Seeds the in-memory providers for the polymorphic witness: a `boards` provider
 * whose boards hold the actual related {@see Note}/{@see Image} objects, plus
 * `notes`/`images` providers so the related to-many fetch can resolve
 * `forType('notes')` — those providers read the related members off the parent
 * board, so their own stores simply hold the same note/image objects.
 *
 * The full object graph is built once (mirroring {@see ArticleProviderFactory}):
 * board 1 pins a note and mixes notes and an image in `items`; board 2 pins an
 * image and has no items — so the to-one resolves both member types and the
 * to-many renders mixed members in seed order.
 */
final class PolymorphicBoardFactory
{
    public static function createBoards(): InMemoryDataProvider
    {
        return new InMemoryDataProvider(
            'boards',
            self::graph()['boards'],
            static fn(object $board): string => $board instanceof Board ? $board->id : '',
        );
    }

    public static function createNotes(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('notes', self::graph()['notes']);
    }

    public static function createImages(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('images', self::graph()['images']);
    }

    /**
     * Builds the seeded object graph: notes and images keyed by id, and the boards
     * wired to hold their related (mixed-type) objects.
     *
     * @return array{
     *     boards: array<string, Board>,
     *     notes: array<string, Note>,
     *     images: array<string, Image>,
     * }
     */
    private static function graph(): array
    {
        $n1 = new Note('n1', 'First note');
        $n2 = new Note('n2', 'Second note');
        $i1 = new Image('i1', 'https://img/1.png');

        $board1 = new Board('1', 'Mixed', $n1, [$n1, $i1, $n2]);
        $board2 = new Board('2', 'Image-pinned', $i1, []);
        // Board 3 pins nothing — the empty-polymorphic-to-one witness: a filter on it
        // still 400s (the unrecognised-key error is gated on the filter being present,
        // not on a target existing — bundle ADR 0068 follow-up #1).
        $board3 = new Board('3', 'Empty', null, []);

        /** @var array<string, Board> $boards */
        $boards = [];
        foreach ([$board1, $board2, $board3] as $board) {
            $boards[$board->id] = $board;
        }

        /** @var array<string, Note> $notes */
        $notes = [];
        foreach ([$n1, $n2] as $note) {
            $notes[$note->id] = $note;
        }

        /** @var array<string, Image> $images */
        $images = [];
        foreach ([$i1] as $image) {
            $images[$image->id] = $image;
        }

        return [
            'boards' => $boards,
            'notes' => $notes,
            'images' => $images,
        ];
    }
}
