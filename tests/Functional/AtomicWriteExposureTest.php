<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteExposure\WriteExposureTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteExposure\WxPersonEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteExposure\WxShelfEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteExposure\WxVaultEntity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * An Atomic Operations batch writes only what its own server routes directly: a type
 * registered on another server is unknown to it, and a type's operations limit which
 * op kinds the batch may apply to it.
 */
#[Group('spec:atomic')]
final class AtomicWriteExposureTest extends JsonApiFunctionalTestCase
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
        $entityManager->persist(new WxVaultEntity(name: 'Vault', owner: $ada));
        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * @param array<string, mixed> $operation
     */
    #[Test]
    #[TestWith([['op' => 'add', 'data' => ['type' => 'wx-vaults', 'attributes' => ['name' => 'Other']]]], 'add')]
    #[TestWith([['op' => 'update', 'ref' => ['type' => 'wx-vaults', 'id' => '1'], 'data' => ['type' => 'wx-vaults', 'id' => '1', 'attributes' => ['name' => 'Renamed']]]], 'update by ref')]
    #[TestWith([['op' => 'update', 'href' => '/internal/wx-vaults/1', 'data' => ['type' => 'wx-vaults', 'id' => '1', 'attributes' => ['name' => 'Renamed']]]], 'update by href')]
    #[TestWith([['op' => 'remove', 'ref' => ['type' => 'wx-vaults', 'id' => '1']]], 'remove')]
    #[TestWith([['op' => 'update', 'ref' => ['type' => 'wx-vaults', 'id' => '1', 'relationship' => 'owner'], 'data' => ['type' => 'wx-people', 'id' => '2']]], 'relationship update')]
    public function aTypeOnAnotherServerIsUnknownToTheBatch(array $operation): void
    {
        $response = $this->atomic('/operations', [$operation]);

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('ATOMIC_TARGET_TYPE_UNKNOWN', $this->firstErrorCode($response));
        $this->assertVaultUnchanged();
    }

    #[Test]
    public function theTypesOwnServerBatchWritesIt(): void
    {
        $response = $this->atomic('/internal/operations', [[
            'op' => 'update',
            'ref' => ['type' => 'wx-vaults', 'id' => '1'],
            'data' => ['type' => 'wx-vaults', 'id' => '1', 'attributes' => ['name' => 'Renamed']],
        ]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $vault = $this->entityManager()->find(WxVaultEntity::class, 1);
        self::assertInstanceOf(WxVaultEntity::class, $vault);
        self::assertSame('Renamed', $vault->name);
    }

    /**
     * @param array<string, mixed> $operation
     */
    #[Test]
    #[TestWith([['op' => 'add', 'data' => ['type' => 'wx-shelves', 'attributes' => ['name' => 'Other']]]], 'read-only add')]
    #[TestWith([['op' => 'update', 'ref' => ['type' => 'wx-shelves', 'id' => '1'], 'data' => ['type' => 'wx-shelves', 'id' => '1', 'attributes' => ['name' => 'Renamed']]]], 'read-only update')]
    #[TestWith([['op' => 'remove', 'ref' => ['type' => 'wx-shelves', 'id' => '1']]], 'read-only remove')]
    #[TestWith([['op' => 'update', 'ref' => ['type' => 'wx-shelves', 'id' => '1', 'relationship' => 'owner'], 'data' => ['type' => 'wx-people', 'id' => '2']]], 'read-only to-one update')]
    #[TestWith([['op' => 'add', 'ref' => ['type' => 'wx-shelves', 'id' => '1', 'relationship' => 'members'], 'data' => [['type' => 'wx-people', 'id' => '2']]]], 'read-only to-many add')]
    #[TestWith([['op' => 'update', 'ref' => ['type' => 'wx-bins', 'id' => '1'], 'data' => ['type' => 'wx-bins', 'id' => '1', 'attributes' => ['name' => 'Renamed']]]], 'no-update update')]
    #[TestWith([['op' => 'update', 'href' => '/wx-bins/1', 'data' => ['type' => 'wx-bins', 'id' => '1', 'attributes' => ['name' => 'Renamed']]]], 'no-update update by href')]
    #[TestWith([['op' => 'remove', 'ref' => ['type' => 'wx-bins', 'id' => '1', 'relationship' => 'members'], 'data' => [['type' => 'wx-people', 'id' => '1']]]], 'no-update to-many remove')]
    public function anOperationTheTypeDoesNotExposeIsRefused(array $operation): void
    {
        $response = $this->atomic('/operations', [$operation]);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('ATOMIC_OPERATION_NOT_EXPOSED', $this->firstErrorCode($response));
        $this->assertShelfUnchanged();
    }

    #[Test]
    public function anOperationTheTypeExposesStillRuns(): void
    {
        $response = $this->atomic('/operations', [
            ['op' => 'add', 'data' => ['type' => 'wx-bins', 'lid' => 'b', 'attributes' => ['name' => 'Bin']]],
            ['op' => 'remove', 'ref' => ['type' => 'wx-bins', 'lid' => 'b']],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    private function assertVaultUnchanged(): void
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();
        $vaults = $entityManager->getRepository(WxVaultEntity::class)->findAll();
        self::assertCount(1, $vaults);
        self::assertSame('Vault', $vaults[0]->name);
        self::assertSame(1, $vaults[0]->owner?->id);
    }

    private function assertShelfUnchanged(): void
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();
        $shelves = $entityManager->getRepository(WxShelfEntity::class)->findAll();
        self::assertCount(1, $shelves);
        self::assertSame('Shelf', $shelves[0]->name);
        self::assertSame(1, $shelves[0]->owner?->id);
        self::assertSame([1], \array_map(static fn(WxPersonEntity $person): ?int => $person->id, $shelves[0]->members->toArray()));
    }

    /**
     * @param list<array<string, mixed>> $operations
     */
    private function atomic(string $path, array $operations): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $ext = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $content = \json_encode(['atomic:operations' => $operations], \JSON_THROW_ON_ERROR);
        $request = Request::create($path, 'POST', server: ['CONTENT_TYPE' => $ext, 'HTTP_ACCEPT' => $ext], content: $content);
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $this->restoreHandlers();

        return $response;
    }

    private function firstErrorCode(Response $response): mixed
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        $first = $errors[0] ?? null;
        self::assertIsArray($first);

        return $first['code'] ?? null;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
