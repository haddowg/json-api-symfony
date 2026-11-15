<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\DataProvider\InMemorySnapshotCoordinator;

/**
 * The in-memory widgets and notes, over one snapshot coordinator so a rollback restores
 * both stores. Seeds note 1, widget 1 holding it, and widget 2.
 */
final class TxFactory
{
    private static ?InMemorySnapshotCoordinator $coordinator = null;

    private static ?InMemoryDataProvider $widgets = null;

    private static ?InMemoryDataProvider $notes = null;

    public static function createWidgets(): InMemoryDataProvider
    {
        return self::widgets();
    }

    public static function createNotes(): InMemoryDataProvider
    {
        return self::notes();
    }

    public static function createWidgetsPersister(): InMemoryDataPersister
    {
        $notes = self::notes();

        return new InMemoryDataPersister(
            'txWidgets',
            self::widgets()->store(),
            static fn(): TxWidget => new TxWidget(),
            static fn(string $type, string $id): ?object => $type === 'txNotes' ? $notes->store()->find($id) : null,
        );
    }

    public static function createNotesPersister(): InMemoryDataPersister
    {
        return new InMemoryDataPersister('txNotes', self::notes()->store(), static fn(): TxNote => new TxNote());
    }

    public static function reset(): void
    {
        self::$coordinator = null;
        self::$widgets = null;
        self::$notes = null;
    }

    private static function coordinator(): InMemorySnapshotCoordinator
    {
        return self::$coordinator ??= new InMemorySnapshotCoordinator();
    }

    private static function notes(): InMemoryDataProvider
    {
        if (self::$notes === null) {
            $note = new TxNote();
            $note->id = 1;
            $note->body = 'seeded';

            self::$notes = new InMemoryDataProvider(
                'txNotes',
                ['1' => $note],
                static fn(object $item): string => (string) ($item instanceof TxNote ? $item->id : ''),
                static function (object $item, string $id): void {
                    \assert($item instanceof TxNote);
                    $item->id = (int) $id;
                },
                coordinator: self::coordinator(),
            );
        }

        return self::$notes;
    }

    private static function widgets(): InMemoryDataProvider
    {
        if (self::$widgets === null) {
            $note = self::notes()->store()->find('1');
            \assert($note instanceof TxNote);

            $first = new TxWidget();
            $first->id = 1;
            $first->name = 'first';
            $first->notes->add($note);

            $second = new TxWidget();
            $second->id = 2;
            $second->name = 'second';

            self::$widgets = new InMemoryDataProvider(
                'txWidgets',
                ['1' => $first, '2' => $second],
                static fn(object $item): string => (string) ($item instanceof TxWidget ? $item->id : ''),
                static function (object $item, string $id): void {
                    \assert($item instanceof TxWidget);
                    $item->id = (int) $id;
                },
                coordinator: self::coordinator(),
            );
        }

        return self::$widgets;
    }
}
