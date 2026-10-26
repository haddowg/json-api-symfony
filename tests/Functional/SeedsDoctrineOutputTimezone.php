<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\DoctrineTimezone\GigEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\DoctrineTimezone\GigPerformerEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\DoctrineTimezone\PerformerEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\DoctrineTimezone\VenueEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\OutputTimezoneFixtures;

/**
 * Seeds the output-timezone kernel's SQLite schema with the values the in-memory
 * fixtures hold, plus one booked performer whose pivot row only Doctrine renders.
 */
trait SeedsDoctrineOutputTimezone
{
    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $fixtureVenue = OutputTimezoneFixtures::venue();
        $venue = new VenueEntity(name: $fixtureVenue->name, openedAt: $fixtureVenue->openedAt);
        $fixtureGig = OutputTimezoneFixtures::gig($fixtureVenue);
        $gig = new GigEntity(
            startsAt: $fixtureGig->startsAt,
            announcedAt: $fixtureGig->announcedAt,
            playedOn: $fixtureGig->playedOn,
            doorsAt: $fixtureGig->doorsAt,
            venue: $venue,
        );
        $performer = new PerformerEntity(name: 'Headliner');
        $entityManager->persist($venue);
        $entityManager->persist($gig);
        $entityManager->persist($performer);
        $entityManager->persist(new GigPerformerEntity(
            gig: $gig,
            performer: $performer,
            bookedAt: OutputTimezoneFixtures::at('2024-04-01 12:00:00'),
            confirmedAt: OutputTimezoneFixtures::at('2024-04-02 12:00:00'),
            contractOn: OutputTimezoneFixtures::at('2024-04-03 00:00:00'),
        ));
        $entityManager->flush();
        $entityManager->clear();
    }
}
