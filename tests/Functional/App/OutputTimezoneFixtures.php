<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * The output-timezone fixtures, shared by the in-memory providers and the Doctrine
 * seed so both kernels serve the same values: one gig at a Paris venue, every value in
 * Paris local time.
 */
final class OutputTimezoneFixtures
{
    public const string ZONE = 'Europe/Paris';

    public static function at(string $local): \DateTimeImmutable
    {
        return new \DateTimeImmutable($local, new \DateTimeZone(self::ZONE));
    }

    public static function venue(): Venue
    {
        return new Venue(1, 'Olympia', self::at('2010-03-15 09:00:00'));
    }

    public static function gig(Venue $venue): Gig
    {
        return new Gig(
            1,
            startsAt: self::at('2024-06-01 21:30:00'),
            announcedAt: self::at('2024-05-01 10:00:00'),
            playedOn: self::at('2024-06-01 00:00:00'),
            doorsAt: self::at('2024-06-01 20:00:00'),
            venue: $venue,
        );
    }

    public static function createGigs(): InMemoryDataProvider
    {
        return self::gigProvider('gigs');
    }

    public static function createGigListings(): InMemoryDataProvider
    {
        return self::gigProvider('gig-listings');
    }

    public static function createRawGigs(): InMemoryDataProvider
    {
        return self::gigProvider('raw-gigs');
    }

    public static function createSharedGigs(): InMemoryDataProvider
    {
        $gig = self::gig(self::venue());
        $gig->performers = [self::performer()];
        $provider = new InMemoryDataProvider('shared-gigs', [1 => $gig]);
        // The pivot row the Doctrine seed's association entity holds.
        $provider->store()->writePivotRows($gig, 'performers', ['1' => [
            'bookedAt' => self::at('2024-04-01 12:00:00'),
            'confirmedAt' => self::at('2024-04-02 12:00:00'),
            'contractOn' => self::at('2024-04-03 00:00:00'),
        ]]);

        return $provider;
    }

    public static function performer(): Performer
    {
        return new Performer(1, 'Headliner');
    }

    public static function createPerformers(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('performers', [1 => self::performer()]);
    }

    public static function createVenues(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('venues', [1 => self::venue()]);
    }

    private static function gigProvider(string $type): InMemoryDataProvider
    {
        return new InMemoryDataProvider($type, [1 => self::gig(self::venue())]);
    }
}
