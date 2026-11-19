<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

/**
 * Note 1 "Plan" holds item 1, note 2 "Broken" holds none, and item 2 is free.
 */
final class TypedRequestMetaSeed
{
    /**
     * @return array{notes: list<TrNote>, items: list<TrItem>, plains: list<TrPlain>}
     */
    public static function build(): array
    {
        $items = [];
        foreach ([1 => ['Pen', ['size' => 3]], 2 => ['Ink', []]] as $id => [$name, $extras]) {
            $item = new TrItem();
            $item->id = $id;
            $item->name = $name;
            $item->extras = $extras;
            $items[] = $item;
        }

        $notes = [];
        foreach ([1 => 'Plan', 2 => 'Broken'] as $id => $title) {
            $note = new TrNote();
            $note->id = $id;
            $note->title = $title;
            $notes[] = $note;
        }
        $notes[0]->items->add($items[0]);
        $notes[0]->lead = $items[0];

        $plain = new TrPlain();
        $plain->id = 1;
        $plain->name = 'Plain';

        return ['notes' => $notes, 'items' => $items, 'plains' => [$plain]];
    }
}
