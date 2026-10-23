<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\IncludeScopingDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingAdvertiser;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingMember;
use PHPUnit\Framework\Attributes\Test;

/**
 * Includes load onto entities whose relations are private with no setter: the loaded
 * members and targets reach the renderer out of band, and the entities keep their own
 * collections and targets. A query extension hides agency 2.
 */
final class IncludeWithoutSetterTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return IncludeScopingDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();

        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (ScopingFixtures::graph() as $entities) {
            foreach ($entities as $entity) {
                $entityManager->persist($entity);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function aToManyWithNoSetterIsIncludedWithoutReplacingTheCollection(): void
    {
        $response = $this->handle('/advertisers?include=agencies');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringNotContainsString('Soft Hidden', (string) $response->getContent());

        $advertiser = $this->entityManager()->find(ScopingAdvertiser::class, 1);
        self::assertInstanceOf(ScopingAdvertiser::class, $advertiser);
        self::assertCount(2, $advertiser->getAgencies(), 'the entity keeps its whole collection');
    }

    #[Test]
    public function aToOneWithNoSetterIsIncludedWithoutReplacingTheTarget(): void
    {
        $response = $this->handle('/members?include=agency');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringNotContainsString('Soft Hidden', (string) $response->getContent());

        $member = $this->entityManager()->find(ScopingMember::class, 4);
        self::assertInstanceOf(ScopingMember::class, $member);
        self::assertSame(2, $member->getAgency()?->id, 'the entity keeps its own target');
    }

    #[Test]
    public function nestedIncludesWithNoSetterLoad(): void
    {
        $response = $this->handle('/advertisers?include=agencies.members,agencies.lead');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringNotContainsString('Soft Hidden', (string) $response->getContent());
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
