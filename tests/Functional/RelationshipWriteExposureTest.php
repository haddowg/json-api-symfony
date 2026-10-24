<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteExposure\WriteExposureTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteExposure\WxPersonEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteExposure\WxShelfEntity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Routing\RouterInterface;

/**
 * A type whose operations exclude Update has no relationship-mutation endpoints:
 * `wx-shelves` is read-only and `wx-bins` lists its operations without Update. Both
 * are entity-mapped, so the generic Doctrine persister would apply the write if the
 * request reached it.
 */
#[Group('spec:updating-relationships')]
final class RelationshipWriteExposureTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return WriteExposureTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $ada = new WxPersonEntity(name: 'Ada');
        $bo = new WxPersonEntity(name: 'Bo');
        $shelf = new WxShelfEntity(name: 'Shelf', owner: $ada);
        $shelf->members->add($ada);

        $entityManager->persist($ada);
        $entityManager->persist($bo);
        $entityManager->persist($shelf);
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    #[TestWith(['wx-shelves'], 'read-only')]
    #[TestWith(['wx-bins'], 'operations without Update')]
    public function noRelationshipMutationRouteIsEmitted(string $type): void
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $names = \array_keys($router->getRouteCollection()->all());

        self::assertContains(\sprintf('jsonapi.%s.relationship.show', $type), $names);
        self::assertContains(\sprintf('jsonapi.%s.related.show', $type), $names);
        self::assertNotContains(\sprintf('jsonapi.%s.relationship.update', $type), $names);
        self::assertNotContains(\sprintf('jsonapi.%s.relationship.add', $type), $names);
        self::assertNotContains(\sprintf('jsonapi.%s.relationship.remove', $type), $names);
    }

    #[Test]
    #[TestWith(['wx-shelves'], 'read-only')]
    #[TestWith(['wx-bins'], 'operations without Update')]
    public function relationshipMutationsAreRefusedAndChangeNothing(string $type): void
    {
        $toOne = $this->handle(\sprintf('/%s/1/relationships/owner', $type), 'PATCH', ['data' => ['type' => 'wx-people', 'id' => '2']]);
        self::assertSame(405, $toOne->getStatusCode());

        $replace = $this->handle(\sprintf('/%s/1/relationships/members', $type), 'PATCH', ['data' => [['type' => 'wx-people', 'id' => '2']]]);
        self::assertSame(405, $replace->getStatusCode());

        $add = $this->handle(\sprintf('/%s/1/relationships/members', $type), 'POST', ['data' => [['type' => 'wx-people', 'id' => '2']]]);
        self::assertSame(405, $add->getStatusCode());

        $remove = $this->handle(\sprintf('/%s/1/relationships/members', $type), 'DELETE', ['data' => [['type' => 'wx-people', 'id' => '1']]]);
        self::assertSame(405, $remove->getStatusCode());

        $this->assertShelfUnchanged();
    }

    #[Test]
    public function theReadEndpointsStillServeTheRelationship(): void
    {
        $response = $this->handle('/wx-shelves/1/relationships/members');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([['type' => 'wx-people', 'id' => '1']], $this->decode($response)['data'] ?? null);
    }

    #[Test]
    public function aHandWrittenRouteToTheRelationshipIsRefusedByTheHandler(): void
    {
        $toOne = $this->handle('/custom/wx-shelves/1/relationships/owner', 'PATCH', ['data' => ['type' => 'wx-people', 'id' => '2']]);
        self::assertSame(404, $toOne->getStatusCode());

        $add = $this->handle('/custom/wx-shelves/1/relationships/members', 'POST', ['data' => [['type' => 'wx-people', 'id' => '2']]]);
        self::assertSame(404, $add->getStatusCode());

        $remove = $this->handle('/custom/wx-shelves/1/relationships/members', 'DELETE', ['data' => [['type' => 'wx-people', 'id' => '1']]]);
        self::assertSame(404, $remove->getStatusCode());

        $this->assertShelfUnchanged();
    }

    private function assertShelfUnchanged(): void
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();
        $shelf = $entityManager->find(WxShelfEntity::class, 1);
        self::assertInstanceOf(WxShelfEntity::class, $shelf);
        self::assertSame(1, $shelf->owner?->id);
        self::assertSame([1], \array_map(static fn(WxPersonEntity $person): ?int => $person->id, $shelf->members->toArray()));
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
