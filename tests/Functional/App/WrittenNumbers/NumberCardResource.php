<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WrittenNumbers;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Integer and number fields at each position, under the rules that compare their values.
 */
final class NumberCardResource extends AbstractResource
{
    public static string $type = 'numberCards';

    public function fields(): array
    {
        return [
            Id::make(),
            Integer::make('picked')->in([1, 2, 3]),
            Integer::make('avoided')->notIn([2]),
            Integer::make('ranked')->enum(Rank::class),
            Decimal::make('weight')->in([1.0, 2.0]),
            Decimal::make('avoidedWeight')->notIn([2.0]),
            Decimal::make('whole')->in([1, 2]),
            ArrayList::make('counts')->of('integer')->uniqueItems(),
            ArrayList::make('weights')->of('number'),
            Obj::make('stats')->fields(Integer::make('plays')->in([1, 2])),
            Integer::make('hooked')->fillUsing(static function (NumberCard $card, mixed $value): NumberCard {
                $card->hooked = $value;
                $card->hookedType = \get_debug_type($value);

                return $card;
            }),
            Str::make('hookedType')->readOnly(),
        ];
    }
}
