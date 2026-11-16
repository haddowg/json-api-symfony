<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\GateOrder\GateLog;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A caller a security gate refuses reaches no lifecycle hook: not the resource's hook
 * methods and not an application listener at the default priority, on any operation, on
 * its own or in an Atomic Operations batch. Runs on the Doctrine and in-memory kernels.
 */
abstract class GateOrderConformanceTestCase extends JsonApiFunctionalTestCase
{
    protected function tearDown(): void
    {
        GateLog::reset();
        parent::tearDown();
    }

    #[Test]
    public function anAllowedWriteRunsTheHooks(): void
    {
        $response = $this->as('admin', '/gateWidgets', 'POST', $this->widget('fresh'));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        foreach (['BeforeSaveEvent', 'beforeSave', 'BeforeCreateEvent', 'beforeCreate', 'afterCreate', 'afterSave'] as $entry) {
            self::assertContains($entry, GateLog::$entries);
        }
    }

    #[Test]
    public function aRefusedCreateRunsNoHook(): void
    {
        $this->assertRefused($this->as('user', '/gateWidgets', 'POST', $this->widget('fresh')));
    }

    #[Test]
    public function anUpdateRefusedOnTheStoredResourceRunsNoHook(): void
    {
        $this->assertRefused($this->as('user', '/gateWidgets/2', 'PATCH', $this->widget('renamed', id: '2')));
    }

    #[Test]
    public function anUpdateRefusedOnTheChangedResourceRunsNoSaveOrUpdateHook(): void
    {
        // The stored widget is the caller's, so the first gate passes; handing it to
        // someone else fails the second.
        $response = $this->as('user', '/gateWidgets/1', 'PATCH', ['data' => ['type' => 'gateWidgets', 'id' => '1', 'attributes' => ['owner' => 'other']]]);

        $this->assertRefused($response, ['BeforeApplyUpdateEvent']);
    }

    #[Test]
    public function anUpdateSettingARelationTheCallerMayNotChangeRunsNoSaveOrUpdateHook(): void
    {
        $response = $this->as('user', '/gateWidgets/1', 'PATCH', [
            'data' => ['type' => 'gateWidgets', 'id' => '1', 'relationships' => ['lockedPartner' => ['data' => null]]],
        ]);

        $this->assertRefused($response, ['BeforeApplyUpdateEvent']);
    }

    #[Test]
    public function aRefusedDeleteRunsNoHook(): void
    {
        $this->assertRefused($this->as('user', '/gateWidgets/1', 'DELETE'));
    }

    #[Test]
    public function aRelationshipWriteRefusedByTheUpdateGateRunsNoHook(): void
    {
        $this->assertRefused($this->as('user', '/gateWidgets/2/relationships/partner', 'PATCH', ['data' => null]));
    }

    #[Test]
    public function aRelationshipWriteRefusedByTheRelationsOwnGateRunsNoHook(): void
    {
        $this->assertRefused($this->as('user', '/gateWidgets/1/relationships/lockedPartner', 'PATCH', ['data' => null]));
    }

    #[Test]
    public function aRefusedReadRunsNoHook(): void
    {
        $this->assertRefused($this->as('user', '/gateWidgets/3'));
    }

    #[Test]
    public function aRelatedReadRefusedByTheParentsReadGateRunsNoHook(): void
    {
        $this->assertRefused($this->as('user', '/gateWidgets/3/partner'));
        $this->assertRefused($this->as('user', '/gateWidgets/3/relationships/partner'));
    }

    #[Test]
    public function aRelatedReadRefusedByTheRelationsOwnGateRunsNoHook(): void
    {
        $this->assertRefused($this->as('user', '/gateWidgets/1/adminPartner'));
        $this->assertRefused($this->as('user', '/gateWidgets/1/relationships/adminPartner'));
    }

    #[Test]
    public function aRefusedCollectionReadRunsNoHook(): void
    {
        $this->assertRefused($this->as('reader', '/gateWidgets'));
    }

    #[Test]
    public function aRefusedActionRunsNoHookOrHandler(): void
    {
        $this->assertRefused($this->as('user', '/gateWidgets/1/-actions/poke', 'POST'));
    }

    #[Test]
    public function aRefusedAtomicOperationRunsNoHook(): void
    {
        $operations = [
            [['op' => 'add', 'data' => ['type' => 'gateWidgets', 'attributes' => ['name' => 'fresh', 'owner' => 'user']]]],
            [['op' => 'update', 'ref' => ['type' => 'gateWidgets', 'id' => '2'], 'data' => ['type' => 'gateWidgets', 'id' => '2', 'attributes' => ['name' => 'renamed']]]],
            [['op' => 'remove', 'ref' => ['type' => 'gateWidgets', 'id' => '1']]],
            [['op' => 'update', 'ref' => ['type' => 'gateWidgets', 'id' => '1', 'relationship' => 'lockedPartner'], 'data' => null]],
        ];

        foreach ($operations as $batch) {
            $this->assertRefused($this->atomicAs('user', $batch));
        }
    }

    /**
     * @param list<string> $entries what ran before the refusing gate: the events that
     *                              passed an earlier gate
     */
    protected function assertRefused(Response $response, array $entries = []): void
    {
        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($entries, GateLog::$entries);
        GateLog::reset();
    }

    /**
     * @param array<string, mixed>|null $body
     */
    protected function as(string $user, string $path, string $method = 'GET', ?array $body = null): Response
    {
        return $this->handle($path, $method, $body, ['HTTP_AUTHORIZATION' => 'Bearer ' . $user]);
    }

    /**
     * @param list<array<string, mixed>> $operations
     */
    protected function atomicAs(string $user, array $operations): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $ext = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $request = Request::create('/operations', 'POST', server: [
            'CONTENT_TYPE' => $ext,
            'HTTP_ACCEPT' => $ext,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $user,
        ], content: \json_encode(['atomic:operations' => $operations], \JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $this->restoreHandlers();

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function widget(string $name, ?string $id = null): array
    {
        $data = ['type' => 'gateWidgets', 'attributes' => ['name' => $name, 'owner' => 'user']];
        if ($id !== null) {
            $data['id'] = $id;
        }

        return ['data' => $data];
    }
}
