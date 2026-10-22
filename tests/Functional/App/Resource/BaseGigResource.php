<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Time;

/**
 * The `gigs` fields both output-timezone kernels share: a `DateTime` that takes the
 * server's zone, a `DateTime` with its own `outputTimezone()`, a `Date` and a `Time`
 * the server zone must leave alone, and a to-one `venue` to include.
 */
abstract class BaseGigResource extends AbstractResource
{
    public static string $type = 'gigs';

    public function fields(): array
    {
        return [
            Id::make(),
            DateTime::make('startsAt'),
            DateTime::make('announcedAt')->outputTimezone('America/New_York'),
            Date::make('playedOn'),
            Time::make('doorsAt'),
            BelongsTo::make('venue', 'venues'),
        ];
    }
}
