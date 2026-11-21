<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotNumbers;

use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Integer;

/**
 * Adds `pins`, a pivot relation with the same numbers, which only Doctrine serves.
 */
final class DoctrinePnBoardResource extends PnBoardResource
{
    public function fields(): array
    {
        return [
            ...parent::fields(),
            BelongsToMany::make('pins', 'pn-cards')
                ->through(PnPin::class)
                ->fields(Integer::make('count'), Decimal::make('weight'))
                ->extractUsing(static fn(mixed $board): array => $board instanceof PnBoard
                    ? \array_values(\array_map(static fn(PnPin $pin): ?PnCard => $pin->card, $board->pins->toArray()))
                    : [])
                ->withData(),
        ];
    }
}
