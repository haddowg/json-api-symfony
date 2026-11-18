<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\DateTimeParser;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\Where;

abstract class BaseShiftResource extends AbstractResource
{
    public static string $type = 'shifts';

    public function fields(): array
    {
        return [
            Id::make()->sortable(),
            DateTime::make('startsAt')->nullable()->sortable(),
            Date::make('day')->nullable(),
            // A hook that receives the raw string, to show it is held to the same shape.
            DateTime::make('rescheduledTo')->writeOnly()->fillUsing(static function (mixed $shift, mixed $value): mixed {
                if (\is_string($value) && ($shift instanceof Shift || $shift instanceof ShiftEntity)) {
                    $shift->startsAt = (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone(\date_default_timezone_get()));
                }

                return $shift;
            }),
        ];
    }

    public function filters(): array
    {
        return [
            DateRange::make('startsRange', 'startsAt'),
            DateRange::make('dayRange', 'day'),
            Where::make('startsFrom', column: 'startsAt', operator: '>=')
                ->deserializeUsing(static fn(mixed $value): mixed => DateTimeParser::tryParse($value) ?? $value),
        ];
    }
}
