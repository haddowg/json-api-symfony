<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\BadgeEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\BadgeEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\MedalEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\MedalEntityFactory;

/**
 * `afterBoot()` for the Doctrine request-aware-predicates suite: creates the
 * in-memory SQLite schema and seeds the one badge ("First", rank "bronze", secret
 * "topsecret", clearance "secret") holding medal 1, plus medals 1-3
 * ("Gold"/"Silver"/"Bronze") — mirroring the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\RequestAwarePredicatesFactory}
 * seed — then clears the unit of work so a subsequent `find()` returns a fresh
 * managed entity.
 */
trait SeedsRequestAwarePredicates
{
    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // No explicit ids: each entity's store-provided `AUTO` column assigns a
        // sequential int in insertion order, so medals get 1-3 and the badge gets 1.
        $medals = [];
        foreach (['Gold', 'Silver', 'Bronze'] as $title) {
            $medals[] = MedalEntityFactory::createOne(['title' => $title]);
        }

        $badge = BadgeEntityFactory::createOne([
            'name' => 'First',
            'secret' => 'topsecret',
            'rank' => 'bronze',
            'clearance' => 'secret',
        ]);

        // The constructor builds the entity without `medals`, so the membership is
        // set by mutating the managed badge's collection and flushing once. The
        // PersistentObjectFactory returns the real managed entities, so the
        // collection is mutated directly (no proxy unwrap needed).
        $first = $medals[0];
        \assert($first instanceof MedalEntity);
        \assert($badge instanceof BadgeEntity);
        $badge->medals->add($first);

        $entityManager->flush();
        $entityManager->clear();
    }
}
