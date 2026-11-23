<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DateMessage;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Resource\Field\Union;
use haddowg\JsonApi\Resource\Filter\DateRange;

/**
 * A date-time, a date and a time each with and without a declared example, a time in
 * its offsetless default format, a date nested in an object, a union and an open object's
 * members, and date ranges over them,
 * to show every bad date reads as its kind's one message.
 */
abstract class BaseBookingResource extends AbstractResource
{
    public static string $type = 'bookings';

    public function fields(): array
    {
        return [
            Id::make()->sortable(),
            DateTime::make('startsAt')->nullable(),
            DateTime::make('endsAt')->nullable()->example('2026-11-05T18:00:00+01:00'),
            Date::make('day')->nullable(),
            Date::make('dueOn')->nullable()->example('2026-12-24'),
            Time::make('opensAt')->format('H:i:sP')->nullable(),
            Time::make('closesAt')->format('H:i:sP')->nullable()->example('17:30:00+01:00'),
            Time::make('breakAt')->nullable(),
            Obj::make('window')->nullable()->fields(DateTime::make('from')->nullable()),
            Union::make('until')->of(Date::make(), Boolean::make())->nullable(),
            Obj::make('marks')->nullable()->additionalProperties(Date::make('mark')),
        ];
    }

    public function filters(): array
    {
        return [
            DateRange::make('startsRange', 'startsAt'),
            DateRange::make('dayRange', 'day'),
            DateRange::make('endsRange', 'endsAt')->example(['min' => '2026-01-01T00:00:00Z', 'max' => '2026-12-31T23:59:59Z']),
        ];
    }
}
