<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Hook\HookLog;
use haddowg\JsonApiBundle\Tests\Functional\App\Hook\HookWidgetFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\LifecycleHooksTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * The lifecycle-hooks keystone suite (bundle ADR 0042). It proves the full hook
 * set fires at the right points and in the right order, that a before-hook
 * mutation is persisted, that a before-hook throw aborts with the thrown status
 * (and no commit happens), that an after-hook replaces the response, that the
 * server-level `serving` hook fires once before any operation, and that read hooks
 * fire on GET.
 *
 * Every behaviour is asserted twice — once against the `hookWidgets` type wired to
 * a cross-cutting **event subscriber**, once against the `hookableWidgets` type
 * whose resource implements the **hook interface** — so the two mechanisms are
 * shown equivalent (the resource methods are sugar over the events). Each
 * behaviour is a private helper invoked from a `*_via_event_subscriber` /
 * `*_via_resource_method` pair (PHPUnit `#[DataProvider]` is deliberately avoided
 * here: this suite boots a kernel without the Foundry bundle, and Foundry's PHPUnit
 * extension boots on every data-provider method).
 */
final class LifecycleHooksTest extends JsonApiFunctionalTestCase
{
    private const string EVENT_TYPE = 'hookWidgets';

    private const string METHOD_TYPE = 'hookableWidgets';

    protected static function getKernelClass(): string
    {
        return LifecycleHooksTestKernel::class;
    }

    protected function afterBoot(): void
    {
        HookWidgetFactory::reset();
        HookLog::reset();
    }

    #[Test]
    public function create_hooks_fire_in_order_via_event_subscriber(): void
    {
        $this->assertCreateHooksFireInOrder(self::EVENT_TYPE);
    }

    #[Test]
    public function create_hooks_fire_in_order_via_resource_method(): void
    {
        $this->assertCreateHooksFireInOrder(self::METHOD_TYPE);
    }

    #[Test]
    public function before_create_mutation_is_persisted_via_event_subscriber(): void
    {
        $this->assertBeforeCreateMutationPersisted(self::EVENT_TYPE);
    }

    #[Test]
    public function before_create_mutation_is_persisted_via_resource_method(): void
    {
        $this->assertBeforeCreateMutationPersisted(self::METHOD_TYPE);
    }

    #[Test]
    public function update_hooks_fire_in_order_via_event_subscriber(): void
    {
        $this->assertUpdateHooksFireInOrder(self::EVENT_TYPE);
    }

    #[Test]
    public function update_hooks_fire_in_order_via_resource_method(): void
    {
        $this->assertUpdateHooksFireInOrder(self::METHOD_TYPE);
    }

    #[Test]
    public function delete_hooks_fire_in_order_via_event_subscriber(): void
    {
        $this->assertDeleteHooksFireInOrder(self::EVENT_TYPE);
    }

    #[Test]
    public function delete_hooks_fire_in_order_via_resource_method(): void
    {
        $this->assertDeleteHooksFireInOrder(self::METHOD_TYPE);
    }

    #[Test]
    public function relationship_hooks_fire_in_order_via_event_subscriber(): void
    {
        $this->assertRelationshipHooksFireInOrder(self::EVENT_TYPE);
    }

    #[Test]
    public function relationship_hooks_fire_in_order_via_resource_method(): void
    {
        $this->assertRelationshipHooksFireInOrder(self::METHOD_TYPE);
    }

    #[Test]
    public function read_one_hook_fires_via_event_subscriber(): void
    {
        $this->assertReadOneHookFires(self::EVENT_TYPE);
    }

    #[Test]
    public function read_one_hook_fires_via_resource_method(): void
    {
        $this->assertReadOneHookFires(self::METHOD_TYPE);
    }

    #[Test]
    public function read_collection_hook_fires_via_event_subscriber(): void
    {
        $this->assertReadCollectionHookFires(self::EVENT_TYPE);
    }

    #[Test]
    public function read_collection_hook_fires_via_resource_method(): void
    {
        $this->assertReadCollectionHookFires(self::METHOD_TYPE);
    }

    #[Test]
    public function before_save_throw_aborts_create_via_event_subscriber(): void
    {
        $this->assertBeforeSaveThrowAbortsCreate(self::EVENT_TYPE);
    }

    #[Test]
    public function before_save_throw_aborts_create_via_resource_method(): void
    {
        $this->assertBeforeSaveThrowAbortsCreate(self::METHOD_TYPE);
    }

    #[Test]
    public function before_delete_throw_aborts_via_event_subscriber(): void
    {
        $this->assertBeforeDeleteThrowAborts(self::EVENT_TYPE);
    }

    #[Test]
    public function before_delete_throw_aborts_via_resource_method(): void
    {
        $this->assertBeforeDeleteThrowAborts(self::METHOD_TYPE);
    }

    #[Test]
    public function before_update_original_is_the_pre_change_snapshot_via_event_subscriber(): void
    {
        $this->assertBeforeUpdateOriginalIsPreChangeSnapshot(self::EVENT_TYPE);
    }

    #[Test]
    public function before_update_original_is_the_pre_change_snapshot_via_resource_method(): void
    {
        $this->assertBeforeUpdateOriginalIsPreChangeSnapshot(self::METHOD_TYPE);
    }

    #[Test]
    public function before_update_throw_aborts_via_event_subscriber(): void
    {
        $this->assertBeforeUpdateThrowAborts(self::EVENT_TYPE);
    }

    #[Test]
    public function before_update_throw_aborts_via_resource_method(): void
    {
        $this->assertBeforeUpdateThrowAborts(self::METHOD_TYPE);
    }

    #[Test]
    public function before_relationship_mutate_throw_aborts_via_event_subscriber(): void
    {
        $this->assertBeforeRelationshipMutateThrowAborts(self::EVENT_TYPE);
    }

    #[Test]
    public function before_relationship_mutate_throw_aborts_via_resource_method(): void
    {
        $this->assertBeforeRelationshipMutateThrowAborts(self::METHOD_TYPE);
    }

    #[Test]
    public function after_create_replaces_response_via_event_subscriber(): void
    {
        $this->assertAfterCreateReplacesResponse(self::EVENT_TYPE);
    }

    #[Test]
    public function after_create_replaces_response_via_resource_method(): void
    {
        $this->assertAfterCreateReplacesResponse(self::METHOD_TYPE);
    }

    #[Test]
    public function after_fetch_one_replaces_response_via_event_subscriber(): void
    {
        $this->assertAfterFetchOneReplacesResponse(self::EVENT_TYPE);
    }

    #[Test]
    public function after_fetch_one_replaces_response_via_resource_method(): void
    {
        $this->assertAfterFetchOneReplacesResponse(self::METHOD_TYPE);
    }

    #[Test]
    public function serving_fires_once_before_the_operation(): void
    {
        $this->handle('/hookWidgets/1');

        self::assertSame('serving', HookLog::entries()[0]);
        self::assertCount(1, \array_filter(HookLog::entries(), static fn(string $e): bool => $e === 'serving'));
    }

    #[Test]
    public function serving_throw_aborts_before_any_operation(): void
    {
        HookLog::$throwAt = 'serving';
        HookLog::$throwStatus = 403;

        $response = $this->handle('/hookWidgets', 'POST', [
            'data' => ['type' => 'hookWidgets', 'attributes' => ['name' => 'created']],
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['serving'], HookLog::entries());
    }

    private function assertCreateHooksFireInOrder(string $type): void
    {
        $response = $this->handle('/' . $type, 'POST', [
            'data' => ['type' => $type, 'attributes' => ['name' => 'created']],
        ]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            ['serving', 'beforeSave', 'beforeCreate', 'afterCreate', 'afterSave'],
            HookLog::entries(),
        );
    }

    private function assertBeforeCreateMutationPersisted(string $type): void
    {
        $created = $this->decode($this->handle('/' . $type, 'POST', [
            'data' => ['type' => $type, 'attributes' => ['name' => 'created']],
        ]));

        $data = $this->dataOf($created);
        $id = $data['id'] ?? null;
        self::assertIsString($id);

        // The before-create hook stamped the entity; the create response (and a
        // follow-up read) reflect the persisted mutation.
        $stamp = $this->attributesOf($created)['stamp'] ?? null;
        self::assertIsString($stamp);
        self::assertNotSame('', $stamp);

        $read = $this->decode($this->handle('/' . $type . '/' . $id));
        self::assertSame($stamp, $this->attributesOf($read)['stamp'] ?? null);
    }

    private function assertUpdateHooksFireInOrder(string $type): void
    {
        $response = $this->handle('/' . $type . '/1', 'PATCH', [
            'data' => ['type' => $type, 'id' => '1', 'attributes' => ['name' => 'renamed']],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['serving', 'beforeSave', 'beforeUpdate', 'afterUpdate', 'afterSave'],
            HookLog::entries(),
        );
    }

    private function assertDeleteHooksFireInOrder(string $type): void
    {
        $response = $this->handle('/' . $type . '/1', 'DELETE');

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(['serving', 'beforeDelete', 'afterDelete'], HookLog::entries());
    }

    private function assertRelationshipHooksFireInOrder(string $type): void
    {
        $response = $this->handle('/' . $type . '/1/relationships/owner', 'PATCH', [
            'data' => ['type' => 'hookOwners', 'id' => '2'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['serving', 'beforeRelationshipMutate', 'afterRelationshipMutate'],
            HookLog::entries(),
        );
    }

    private function assertReadOneHookFires(string $type): void
    {
        $this->handle('/' . $type . '/1');

        self::assertSame(['serving', 'afterFetchOne'], HookLog::entries());
    }

    private function assertReadCollectionHookFires(string $type): void
    {
        $this->handle('/' . $type);

        self::assertSame(['serving', 'afterFetchCollection'], HookLog::entries());
    }

    private function assertBeforeSaveThrowAbortsCreate(string $type): void
    {
        HookLog::$throwAt = 'beforeSave';
        HookLog::$throwStatus = 422;

        $response = $this->handle('/' . $type, 'POST', [
            'data' => ['type' => $type, 'attributes' => ['name' => 'created']],
        ]);

        self::assertSame(422, $response->getStatusCode());
        // serving + beforeSave ran; the operation never reached create.
        self::assertSame(['serving', 'beforeSave'], HookLog::entries());

        // No commit happened: the collection still holds only the seeded widget.
        $items = $this->decode($this->handle('/' . $type))['data'] ?? null;
        self::assertIsArray($items);
        self::assertCount(1, $items);
    }

    private function assertBeforeDeleteThrowAborts(string $type): void
    {
        HookLog::$throwAt = 'beforeDelete';
        HookLog::$throwStatus = 409;

        $response = $this->handle('/' . $type . '/1', 'DELETE');

        self::assertSame(409, $response->getStatusCode());

        // The resource still exists: the delete never ran.
        self::assertSame(200, $this->handle('/' . $type . '/1')->getStatusCode());
    }

    private function assertBeforeUpdateOriginalIsPreChangeSnapshot(string $type): void
    {
        // The seeded widget #1 is named `first`; a PATCH renames it to `renamed`.
        // The before-update hook records the diff it observed — the original
        // snapshot must hold the prior `first`, the entity the incoming `renamed`.
        HookLog::$captureUpdateDiff = true;

        $response = $this->handle('/' . $type . '/1', 'PATCH', [
            'data' => ['type' => $type, 'id' => '1', 'attributes' => ['name' => 'renamed']],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['serving', 'beforeSave', 'beforeUpdate:original=first,entity=renamed', 'afterUpdate', 'afterSave'],
            HookLog::entries(),
        );
    }

    private function assertBeforeUpdateThrowAborts(string $type): void
    {
        HookLog::$throwAt = 'beforeUpdate';
        HookLog::$throwStatus = 403;

        $response = $this->handle('/' . $type . '/1', 'PATCH', [
            'data' => ['type' => $type, 'id' => '1', 'attributes' => ['name' => 'renamed']],
        ]);

        self::assertSame(403, $response->getStatusCode());
        // serving + beforeSave + beforeUpdate ran; the throw aborted before the
        // persister committed — no afterUpdate / afterSave fired. (The unchanged-row
        // guarantee is asserted against the transactional Doctrine store in
        // DoctrineLifecycleHooksTest::before_update_throw_aborts_with_no_commit;
        // the in-memory witness holds the loaded object by reference, so the
        // pre-gate in-place hydration is visible to a follow-up read — it cannot
        // roll back like a real EntityManager.)
        self::assertSame(['serving', 'beforeSave', 'beforeUpdate'], HookLog::entries());
    }

    private function assertBeforeRelationshipMutateThrowAborts(string $type): void
    {
        HookLog::$throwAt = 'beforeRelationshipMutate';
        HookLog::$throwStatus = 403;

        $response = $this->handle('/' . $type . '/1/relationships/owner', 'PATCH', [
            'data' => ['type' => 'hookOwners', 'id' => '2'],
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['serving', 'beforeRelationshipMutate'], HookLog::entries());

        // The apply never ran: the linkage still points at the seeded owner #1.
        $linkage = $this->decode($this->handle('/' . $type . '/1/relationships/owner'))['data'] ?? null;
        self::assertIsArray($linkage);
        self::assertSame('1', $linkage['id'] ?? null);
    }

    private function assertAfterCreateReplacesResponse(string $type): void
    {
        HookLog::$replaceAt = 'afterCreate';

        $document = $this->decode($this->handle('/' . $type, 'POST', [
            'data' => ['type' => $type, 'attributes' => ['name' => 'created']],
        ]));

        self::assertSame('afterCreate', $this->metaOf($document)['replacedBy'] ?? null);
    }

    private function assertAfterFetchOneReplacesResponse(string $type): void
    {
        HookLog::$replaceAt = 'afterFetchOne';

        $document = $this->decode($this->handle('/' . $type . '/1'));

        self::assertSame('afterFetchOne', $this->metaOf($document)['replacedBy'] ?? null);
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

    /**
     * The decoded document's top-level `meta`, narrowed for offset access.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function metaOf(array $document): array
    {
        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);

        /** @var array<string, mixed> $meta */
        return $meta;
    }
}
