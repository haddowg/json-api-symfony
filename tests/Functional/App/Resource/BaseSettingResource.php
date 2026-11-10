<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Union;

/**
 * The union witness, shared by both providers: unions at the top level, inside an `Obj`
 * and as a list's elements, one required, one with an enum member and two with a `Date`
 * member, so each member's rules and storage can be checked where the union sits. A
 * required field of the application's own schema sits beside them.
 */
abstract class BaseSettingResource extends AbstractResource
{
    public static string $type = 'settings';

    public function fields(): array
    {
        return [
            Id::make(),
            Union::make('value')->of(
                Str::make()->maxLength(10),
                Decimal::make(),
                Boolean::make(),
                ArrayList::make()->maxItems(3)->of(Union::of(Str::make()->maxLength(5), Decimal::make(), Boolean::make())),
            )->nullable(),
            Union::make('answer')->of(
                Str::make(),
                Integer::make(),
                Boolean::make(),
                ArrayList::make()->of(Union::of(Str::make(), Integer::make())),
            )->required(),
            Union::make('threshold')->of(Str::make()->in(['auto', 'off']), Integer::make()->min(0))->nullable(),
            Union::make('startsOn')->of(Date::make(), Boolean::make())->nullable(),
            Obj::make('window')->nullable()->fields(
                Union::make('until')->of(Date::make(), Boolean::make()),
                Union::make('cap')->of(Str::make()->maxLength(5), Integer::make()->max(10)),
            ),
            ArrayList::make('marks')->nullable()->of(Union::of(Date::make(), Integer::make())),
            CurrencyCodeField::required('currency'),
        ];
    }
}
