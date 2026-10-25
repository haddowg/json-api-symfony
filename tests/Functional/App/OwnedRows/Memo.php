<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OwnedRows;

/**
 * A memo kept outside Doctrine, pinned to a holder (a folder or a widget, by id) and
 * owned by one user.
 */
final class Memo
{
    public function __construct(
        public int $id,
        public int $holderId,
        public string $owner,
        public string $text,
    ) {}

    /**
     * The fixture's memos: holder 1 has memos 1 and 3 by ada and memo 2 by grace.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            new self(1, 1, 'ada', 'first'),
            new self(2, 1, 'grace', 'second'),
            new self(3, 1, 'ada', 'third'),
        ];
    }

    /**
     * The memos pinned to `$holder`, by its `id`.
     *
     * @return list<self>
     */
    public static function heldBy(object $holder): array
    {
        $id = \property_exists($holder, 'id') ? $holder->id : null;

        return \array_values(\array_filter(self::all(), static fn(self $memo): bool => $memo->holderId === $id));
    }
}
