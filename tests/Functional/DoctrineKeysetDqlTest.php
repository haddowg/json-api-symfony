<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql\KeysetDqlDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql\LeagueEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql\LeagueTeamEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql\MemberEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql\TeamEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql\TeamFixtures;

final class DoctrineKeysetDqlTest extends KeysetDqlConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return KeysetDqlDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $league = new LeagueEntity();
        $entityManager->persist($league);
        foreach (TeamFixtures::data() as $id => $row) {
            $team = new TeamEntity(name: $row['name'], base: $row['base'], bonus: $row['bonus']);
            $entityManager->persist($team);
            $league->teams->add($team);
            $entityManager->persist(new LeagueTeamEntity(league: $league, team: $team, slot: $id));
            foreach ($row['scores'] as $score) {
                $entityManager->persist(new MemberEntity(team: $team, score: $score));
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    protected function collectionPaths(): array
    {
        return [...parent::collectionPaths(), '/leagues/1/teams'];
    }
}
