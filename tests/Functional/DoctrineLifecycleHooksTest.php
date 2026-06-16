<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineLifecycleHooksTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\HookOwnerEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\HookWidgetEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Hook\HookLog;
use PHPUnit\Framework\Attributes\Test;

/**
 * The lifecycle-hooks suite against the **Doctrine** persist/flush path (bundle
 * ADR 0042): the resource-method mechanism witness over real SQL. It mirrors the
 * resource-method assertions of the in-memory {@see LifecycleHooksTest} — order,
 * before-mutation persisted, before-throw aborts with no commit, after-replace,
 * read hooks — so a hook regression localizes to a provider, not the seam.
 */
final class DoctrineLifecycleHooksTest extends JsonApiFunctionalTestCase
{
    private const string TYPE = 'hookableWidgets';

    protected static function getKernelClass(): string
    {
        return DoctrineLifecycleHooksTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $entityManager->persist(new HookOwnerEntity(1, 'Ada'));
        $entityManager->persist(new HookOwnerEntity(2, 'Grace'));
        $entityManager->persist(new HookWidgetEntity(1, 'first', '', null));
        $entityManager->flush();
        $entityManager->clear();

        HookLog::reset();
    }

    #[Test]
    public function create_hooks_fire_in_order(): void
    {
        $response = $this->handle('/' . self::TYPE, 'POST', [
            'data' => ['type' => self::TYPE, 'attributes' => ['name' => 'created']],
        ]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            ['beforeSave', 'beforeCreate', 'afterCreate', 'afterSave'],
            HookLog::entries(),
        );
    }

    #[Test]
    public function before_create_mutation_is_persisted(): void
    {
        $created = $this->decode($this->handle('/' . self::TYPE, 'POST', [
            'data' => ['type' => self::TYPE, 'attributes' => ['name' => 'created']],
        ]));

        $id = $this->dataOf($created)['id'] ?? null;
        self::assertIsString($id);
        self::assertSame('method-stamped', $this->attributesOf($created)['stamp'] ?? null);

        // A follow-up read against the flushed row reflects the stamped mutation.
        $read = $this->decode($this->handle('/' . self::TYPE . '/' . $id));
        self::assertSame('method-stamped', $this->attributesOf($read)['stamp'] ?? null);
    }

    #[Test]
    public function update_hooks_fire_in_order(): void
    {
        $response = $this->handle('/' . self::TYPE . '/1', 'PATCH', [
            'data' => ['type' => self::TYPE, 'id' => '1', 'attributes' => ['name' => 'renamed']],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['beforeSave', 'beforeUpdate', 'afterUpdate', 'afterSave'],
            HookLog::entries(),
        );
    }

    #[Test]
    public function delete_hooks_fire_in_order(): void
    {
        $response = $this->handle('/' . self::TYPE . '/1', 'DELETE');

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(['beforeDelete', 'afterDelete'], HookLog::entries());
    }

    #[Test]
    public function relationship_hooks_fire_in_order(): void
    {
        $response = $this->handle('/' . self::TYPE . '/1/relationships/owner', 'PATCH', [
            'data' => ['type' => 'hookOwners', 'id' => '2'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['beforeRelationshipMutate', 'afterRelationshipMutate'],
            HookLog::entries(),
        );
    }

    #[Test]
    public function read_one_hook_fires(): void
    {
        $this->handle('/' . self::TYPE . '/1');

        self::assertSame(['afterFetchOne'], HookLog::entries());
    }

    #[Test]
    public function before_save_throw_aborts_create_with_no_commit(): void
    {
        HookLog::$throwAt = 'beforeSave';
        HookLog::$throwStatus = 422;

        $response = $this->handle('/' . self::TYPE, 'POST', [
            'data' => ['type' => self::TYPE, 'attributes' => ['name' => 'created']],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['beforeSave'], HookLog::entries());

        // No row was written: the collection still holds only the seeded widget.
        $items = $this->decode($this->handle('/' . self::TYPE))['data'] ?? null;
        self::assertIsArray($items);
        self::assertCount(1, $items);
    }

    #[Test]
    public function before_delete_throw_aborts_with_no_commit(): void
    {
        HookLog::$throwAt = 'beforeDelete';
        HookLog::$throwStatus = 409;

        $response = $this->handle('/' . self::TYPE . '/1', 'DELETE');

        self::assertSame(409, $response->getStatusCode());

        // The row survives: the delete never ran.
        self::assertSame(200, $this->handle('/' . self::TYPE . '/1')->getStatusCode());
    }

    #[Test]
    public function before_update_original_is_the_pre_change_snapshot(): void
    {
        // The seeded row is named `first`; a PATCH renames it to `renamed`. The
        // hook records the diff it observed — the original snapshot must hold the
        // prior `first` (the handler clones before the in-place dirty hydration),
        // the entity the incoming `renamed`.
        HookLog::$captureUpdateDiff = true;

        $response = $this->handle('/' . self::TYPE . '/1', 'PATCH', [
            'data' => ['type' => self::TYPE, 'id' => '1', 'attributes' => ['name' => 'renamed']],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['beforeSave', 'beforeUpdate:original=first,entity=renamed', 'afterUpdate', 'afterSave'],
            HookLog::entries(),
        );
    }

    #[Test]
    public function before_update_throw_aborts_with_no_commit(): void
    {
        HookLog::$throwAt = 'beforeUpdate';
        HookLog::$throwStatus = 403;

        $response = $this->handle('/' . self::TYPE . '/1', 'PATCH', [
            'data' => ['type' => self::TYPE, 'id' => '1', 'attributes' => ['name' => 'renamed']],
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['beforeSave', 'beforeUpdate'], HookLog::entries());

        // The loaded entity was hydrated dirty in place before beforeUpdate; only
        // the absent flush keeps the row clean. A fresh read (new request, cleared
        // EM) proves the column was never written.
        $read = $this->decode($this->handle('/' . self::TYPE . '/1'));
        self::assertSame('first', $this->attributesOf($read)['name'] ?? null);
    }

    #[Test]
    public function before_relationship_mutate_throw_aborts_with_no_commit(): void
    {
        HookLog::$throwAt = 'beforeRelationshipMutate';
        HookLog::$throwStatus = 403;

        $response = $this->handle('/' . self::TYPE . '/1/relationships/owner', 'PATCH', [
            'data' => ['type' => 'hookOwners', 'id' => '2'],
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['beforeRelationshipMutate'], HookLog::entries());

        // The apply never ran: the seeded row's owner is still absent (data: null).
        $linkage = $this->decode($this->handle('/' . self::TYPE . '/1/relationships/owner'));
        self::assertArrayHasKey('data', $linkage);
        self::assertNull($linkage['data']);
    }

    #[Test]
    public function after_create_replaces_the_response(): void
    {
        HookLog::$replaceAt = 'afterCreate';

        $document = $this->decode($this->handle('/' . self::TYPE, 'POST', [
            'data' => ['type' => self::TYPE, 'attributes' => ['name' => 'created']],
        ]));

        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertSame('afterCreate', $meta['replacedBy'] ?? null);
    }

    /**
     * The decoded document's primary `data` object, narrowed for offset access.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function dataOf(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * The decoded document's `data.attributes`, narrowed for offset access.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function attributesOf(array $document): array
    {
        $attributes = $this->dataOf($document)['attributes'] ?? null;
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }
}
