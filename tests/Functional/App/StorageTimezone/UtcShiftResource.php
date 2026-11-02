<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\DateTimeParser;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The shifts kept in UTC columns, filterable by range and paged by cursor on either
 * date-time. `loggedAt` and `onDay` are read-and-sort only, for paging on plain
 * mutable columns.
 */
#[AsJsonApiResource(entity: UtcShiftEntity::class)]
final class UtcShiftResource extends AbstractResource
{
    public static string $type = 'utcShifts';

    public function fields(): array
    {
        return [
            Id::make()->sortable(),
            DateTime::make('startsAt')->nullable()->sortable(),
            // The column is mutable, and core hydrates a DateTimeImmutable.
            DateTime::make('endsAt')->nullable()->sortable()
                ->deserializeUsing(static function (mixed $value): mixed {
                    $parsed = DateTimeParser::tryParse($value);

                    return $parsed !== null ? \DateTime::createFromImmutable($parsed) : $value;
                }),
            DateTime::make('loggedAt')->nullable()->sortable(),
            Date::make('onDay')->nullable()->sortable(),
        ];
    }

    public function filters(): array
    {
        return [
            DateRange::make('startsRange', 'startsAt'),
            DateRange::make('endsRange', 'endsAt'),
        ];
    }

    public function pagination(?PaginatorInterface $serverDefault): PaginatorInterface
    {
        return CursorPaginator::make()->withDefaultSize(50);
    }
}
