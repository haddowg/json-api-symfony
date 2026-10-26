<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Link\DeclaredLink;
use haddowg\JsonApiBundle\Tests\Functional\App\DoctrineTimezone\VenueEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Venue;

/**
 * The `venues` fields both output-timezone kernels share, so an included venue's
 * `DateTime` shows the server zone reaching `included`.
 *
 * Its declared links run on both kernels too. `map` is a link object with a relative
 * href and a `DateTime` meta member, so each server's base URI and zone reach it;
 * `website` is a bare link; `tickets` resolves to `null` for a venue that is already
 * open, which leaves it out.
 */
abstract class BaseVenueResource extends AbstractResource
{
    public static string $type = 'venues';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            DateTime::make('openedAt'),
        ];
    }

    public function links(): array
    {
        return [
            DeclaredLink::make('map')
                ->title('Venue map')
                ->meta(DateTime::make('openedAt')->example('2010-03-15T08:00:00+00:00'))
                ->linkUsing(static fn(mixed $venue): ?array => $venue instanceof Venue || $venue instanceof VenueEntity
                    ? ['href' => '/venues/' . $venue->id . '/map', 'meta' => ['openedAt' => $venue->openedAt]]
                    : null)
                ->example('https://example.test/venues/1/map'),
            DeclaredLink::make('website')
                ->hrefUsing(static fn(mixed $venue): ?string => $venue instanceof Venue || $venue instanceof VenueEntity
                    ? 'https://venues.example.test/' . $venue->id
                    : null)
                ->example('https://venues.example.test/1'),
            DeclaredLink::make('tickets')
                ->hrefUsing(static fn(mixed $venue): ?string => ($venue instanceof Venue || $venue instanceof VenueEntity)
                    && $venue->openedAt > new \DateTimeImmutable()
                    ? 'https://tickets.example.test/venues/' . $venue->id
                    : null)
                ->example('https://tickets.example.test/venues/1'),
        ];
    }
}
