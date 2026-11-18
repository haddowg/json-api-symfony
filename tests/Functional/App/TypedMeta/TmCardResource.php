<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedMeta;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Cards declare a typed top-level meta on every document they answer with: how many of a
 * collection's cards are open, set by a hook, and a note only an After hook sets; an
 * etag on a fetch and an update; and whether a create made the card.
 */
abstract class TmCardResource extends AbstractResource
{
    public static string $type = 'tm-cards';

    public function getType(mixed $object): string
    {
        return $object instanceof TmCard ? static::$type : '';
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Boolean::make('done'),
            BelongsTo::make('board', 'tm-boards')->nullable(),
        ];
    }

    public function documentMeta(OperationType $operation): iterable
    {
        $etag = Str::make('etag')->required()->example('card-1-0')
            ->extractUsing(static fn(mixed $card): string => $card instanceof TmCard ? 'card-' . $card->id . '-' . (int) $card->done : '');

        return match ($operation) {
            OperationType::FetchCollection => [
                Integer::make('open')->required()->example(2)->describedAs('How many of these cards are open.')
                    ->extractUsing(static fn(mixed $cards): int => \count(\array_filter(
                        \is_array($cards) ? $cards : [],
                        static fn(mixed $card): bool => $card instanceof TmCard && !$card->done,
                    ))),
                Str::make('note')->example('Sprint 4'),
            ],
            OperationType::FetchOne, OperationType::Update => [$etag],
            OperationType::Create => [Boolean::make('fresh')->required()->example(true)->extractUsing(static fn(): bool => true)],
            default => [],
        };
    }
}
