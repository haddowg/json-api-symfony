<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PlaylistEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PlaylistTrackEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\TrackEntity;

/**
 * Seeds the `belongsToMany` pivot fixture for the Doctrine pivot suite: tracks, a
 * playlist owning them through {@see PlaylistTrackEntity} association-entity rows
 * carrying real pivot values (`position`, `addedAt`), and a second playlist for
 * per-parent scoping.
 *
 * The pivot values are chosen so the assertions are unambiguous: per playlist 1,
 * `position` orders Intro(1), Outro(2), Bridge(3) — so `?sort=position` ascends and
 * `?sort=-position` descends (the order flips); `?filter[position]=2` narrows to
 * Outro; and a related `?filter[title]` containing "o" (Intro, Outro) composes with
 * `?sort=position` into one full page of two.
 *
 * Playlist 3 carries **duplicate membership** — Intro at two positions and Outro at
 * one — so the suite can prove the page total counts distinct members (two, not the
 * three association rows) and a windowed page never splits a member across pages
 * ({@see DoctrinePivotRelatedCollectionTest::aPivotRelatedCollectionDedupesDuplicateMembership}).
 */
trait SeedsDoctrinePivot
{
    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // Tracks 1..3 in insertion order (store-assigned ids).
        $intro = new TrackEntity(title: 'Intro');
        $outro = new TrackEntity(title: 'Outro');
        $bridge = new TrackEntity(title: 'Bridge');
        foreach ([$intro, $outro, $bridge] as $track) {
            $entityManager->persist($track);
        }

        $playlist = new PlaylistEntity(name: 'Set');
        $other = new PlaylistEntity(name: 'Encore');
        $repeats = new PlaylistEntity(name: 'Repeats');
        $entityManager->persist($playlist);
        $entityManager->persist($other);
        $entityManager->persist($repeats);

        // Playlist 1: Intro@1, Outro@2, Bridge@3 — inserted out of position order so
        // the order assertions cannot accidentally pass on insertion order.
        $rows = [
            [$playlist, $outro, 2, '2024-01-02T00:00:00+00:00'],
            [$playlist, $intro, 1, '2024-01-01T00:00:00+00:00'],
            [$playlist, $bridge, 3, '2024-01-03T00:00:00+00:00'],
            // Playlist 2 shares Intro, so per-parent scoping must not bleed.
            [$other, $intro, 1, '2024-02-01T00:00:00+00:00'],
            // Playlist 3: Intro appears TWICE (positions 1 and 3) plus Outro once —
            // duplicate membership. Two distinct members across three association
            // rows, so the page total must be two and no member may split a page.
            [$repeats, $intro, 1, '2024-03-01T00:00:00+00:00'],
            [$repeats, $outro, 2, '2024-03-02T00:00:00+00:00'],
            [$repeats, $intro, 3, '2024-03-03T00:00:00+00:00'],
        ];

        foreach ($rows as [$parent, $track, $position, $addedAt]) {
            $entityManager->persist(new PlaylistTrackEntity(
                playlist: $parent,
                track: $track,
                position: $position,
                addedAt: new \DateTimeImmutable($addedAt),
            ));
        }

        $entityManager->flush();
        $entityManager->clear();
    }
}
