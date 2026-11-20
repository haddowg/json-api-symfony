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
 * etag on a fetch and an update; whether a create made the card; and the title of the
 * card a delete removed, in the meta-only document it answers with.
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

    public function documentMeta(OperationType $operation, ?string $relation = null): iterable
    {
        $etag = Str::make('etag')->required()->example('card-1-0')
            ->extractUsing(static fn(TmCard $card): string => 'card-' . $card->id . '-' . (int) $card->done);

        return match ($operation) {
            OperationType::FetchCollection => [
                Integer::make('open')->required()->example(2)->describedAs('How many of these cards are open.')
                    ->extractUsing(static fn(array $cards): int => \count(\array_filter(
                        $cards,
                        static fn(mixed $card): bool => $card instanceof TmCard && !$card->done,
                    ))),
                Str::make('note')->example('Sprint 4'),
            ],
            OperationType::FetchOne, OperationType::Update => [$etag],
            OperationType::Create => [
                Boolean::make('fresh')->required()->example(true)->extractUsing(static fn(): bool => true),
                // A card titled "Broken" ranks as a list, which the integer member refuses.
                Integer::make('rank')->example(1)->extractUsing(static fn(TmCard $card): mixed => $card->title === 'Broken' ? ['wrong'] : 1),
            ],
            // Delete: the title of the card it removed.
            OperationType::Delete => [Str::make('removed')->required()->example('Plan')->extractUsing(static fn(TmCard $card): string => $card->title)],
        };
    }
}
