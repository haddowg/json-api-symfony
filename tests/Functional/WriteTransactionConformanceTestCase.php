<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Event\AfterActionEvent;
use haddowg\JsonApiBundle\Event\AfterCreateEvent;
use haddowg\JsonApiBundle\Event\BeforeActionEvent;
use haddowg\JsonApiBundle\Event\BeforeApplyUpdateEvent;
use haddowg\JsonApiBundle\Event\BeforeCreateEvent;
use haddowg\JsonApiBundle\Event\BeforeDeleteEvent;
use haddowg\JsonApiBundle\Event\BeforeRelationshipCommitEvent;
use haddowg\JsonApiBundle\Event\BeforeRelationshipMutateEvent;
use haddowg\JsonApiBundle\Event\BeforeSaveEvent;
use haddowg\JsonApiBundle\Event\BeforeUpdateEvent;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\TxScenario;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Every single write runs in one transaction, from its first write step through the
 * persister's write: a before hook writes a note through the notes persister (a flush, on
 * Doctrine), a later step throws, and neither the note nor the write survives. A writing
 * action runs the same way, a GET action does not, and an Atomic Operations batch keeps its
 * own transaction. Runs on the Doctrine and in-memory kernels.
 */
abstract class WriteTransactionConformanceTestCase extends JsonApiFunctionalTestCase
{
    protected function tearDown(): void
    {
        TxScenario::reset();
        parent::tearDown();
    }

    #[Test]
    public function aCreateKeepsWhatABeforeHookWroteWhenNothingThrows(): void
    {
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;

        $response = $this->handle('/txWidgets', 'POST', $this->widget('third'));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['seeded', 'by BeforeSaveEvent'], $this->notes());
        self::assertSame(['first', 'second', 'third'], $this->widgetNames());
    }

    #[Test]
    public function aCreateRollsBackWhatABeforeHookWroteWhenALaterHookThrows(): void
    {
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;
        TxScenario::$throwOn = BeforeCreateEvent::class;

        $response = $this->handle('/txWidgets', 'POST', $this->widget('third', notes: ['1']));

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['seeded'], $this->notes());
        self::assertSame(['first', 'second'], $this->widgetNames());
    }

    #[Test]
    public function anUpdateRollsBackWhatABeforeHookWroteWhenALaterHookThrows(): void
    {
        TxScenario::$sideWriteOn = BeforeApplyUpdateEvent::class;
        TxScenario::$throwOn = BeforeUpdateEvent::class;

        $response = $this->handle('/txWidgets/1', 'PATCH', $this->widget('renamed', id: '1', notes: []));

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['seeded'], $this->notes());
        self::assertSame(['first', 'second'], $this->widgetNames());
        self::assertSame(['1'], $this->linkage('/txWidgets/1/relationships/notes'));
    }

    #[Test]
    public function aDeleteRollsBackWhatABeforeHookWroteWhenItThrows(): void
    {
        TxScenario::$sideWriteOn = BeforeDeleteEvent::class;
        TxScenario::$throwOn = BeforeDeleteEvent::class;

        $response = $this->handle('/txWidgets/2', 'DELETE');

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['seeded'], $this->notes());
        self::assertSame(['first', 'second'], $this->widgetNames());
    }

    #[Test]
    public function aDeleteKeepsWhatABeforeHookWroteWhenNothingThrows(): void
    {
        TxScenario::$sideWriteOn = BeforeDeleteEvent::class;

        $response = $this->handle('/txWidgets/2', 'DELETE');

        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['seeded', 'by BeforeDeleteEvent'], $this->notes());
        self::assertSame(['first'], $this->widgetNames());
    }

    #[Test]
    public function aRelationshipReplaceRollsBackWhatABeforeHookWroteWhenALaterHookThrows(): void
    {
        $this->assertRelationshipWriteRollsBack('PATCH', '1', []);
    }

    #[Test]
    public function aRelationshipAddRollsBackWhatABeforeHookWroteWhenALaterHookThrows(): void
    {
        $this->assertRelationshipWriteRollsBack('POST', '2', ['1']);
    }

    #[Test]
    public function aRelationshipRemoveRollsBackWhatABeforeHookWroteWhenALaterHookThrows(): void
    {
        $this->assertRelationshipWriteRollsBack('DELETE', '1', ['1']);
    }

    #[Test]
    public function aCreateAcceptedForProcessingCommitsWhatItsBeforeHooksWrote(): void
    {
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;
        TxScenario::$accept = true;

        $response = $this->handle('/txWidgets', 'POST', $this->widget('third'));

        self::assertSame(202, $response->getStatusCode(), (string) $response->getContent());
        TxScenario::reset();
        self::assertSame(['seeded', 'by BeforeSaveEvent'], $this->notes());
        self::assertSame(['first', 'second'], $this->widgetNames());
        $this->assertNoTransactionOpen();
    }

    #[Test]
    public function anUpdateAcceptedForProcessingCommitsWhatItsBeforeHooksWrote(): void
    {
        TxScenario::$sideWriteOn = BeforeUpdateEvent::class;
        TxScenario::$accept = true;

        $response = $this->handle('/txWidgets/1', 'PATCH', $this->widget('renamed', id: '1'));

        self::assertSame(202, $response->getStatusCode(), (string) $response->getContent());
        TxScenario::reset();
        self::assertSame(['seeded', 'by BeforeUpdateEvent'], $this->notes());
        $this->assertNoTransactionOpen();
    }

    #[Test]
    public function aWritingActionKeepsItsWritesWhenNothingThrows(): void
    {
        $response = $this->handle('/txWidgets/1/-actions/stamp', 'POST');

        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['seeded', 'stamped'], $this->notes());
        self::assertSame(['stamped', 'second'], $this->widgetNames());
    }

    #[Test]
    public function aWritingActionRollsBackWhenItsHandlerThrows(): void
    {
        TxScenario::$sideWriteOn = BeforeActionEvent::class;
        TxScenario::$actionThrows = true;

        $response = $this->handle('/txWidgets/1/-actions/stamp', 'POST');

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['seeded'], $this->notes());
        self::assertSame(['first', 'second'], $this->widgetNames());
    }

    #[Test]
    public function aWritingActionRollsBackWhenItsAfterEventThrows(): void
    {
        TxScenario::$throwOn = AfterActionEvent::class;

        $response = $this->handle('/txWidgets/1/-actions/stamp', 'POST');

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['seeded'], $this->notes());
        self::assertSame(['first', 'second'], $this->widgetNames());
    }

    #[Test]
    public function aWritingCollectionActionRollsBackWhenItsHandlerThrows(): void
    {
        TxScenario::$actionThrows = true;

        $response = $this->handle('/txWidgets/-actions/sweep', 'POST');

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['seeded'], $this->notes());
    }

    #[Test]
    public function aGetActionRunsWithoutATransaction(): void
    {
        TxScenario::$actionThrows = true;

        $response = $this->handle('/txWidgets/1/-actions/peek');

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['seeded', 'peeked'], $this->notes());
    }

    #[Test]
    public function aHookTransactionCommittedAfterTheWriteKeepsEverything(): void
    {
        TxScenario::$nestOn = BeforeSaveEvent::class;
        TxScenario::$sideWriteOn = BeforeCreateEvent::class;
        TxScenario::$unnestOn = AfterCreateEvent::class;

        $response = $this->handle('/txWidgets', 'POST', $this->widget('third'));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['seeded', 'by BeforeCreateEvent'], $this->notes());
        self::assertSame(['first', 'second', 'third'], $this->widgetNames());
        $this->assertNoTransactionOpen();

        TxScenario::reset();
        self::assertSame(201, $this->handle('/txWidgets', 'POST', $this->widget('fourth'))->getStatusCode());
        self::assertSame(['first', 'second', 'third', 'fourth'], $this->widgetNames());
    }

    #[Test]
    public function aHookTransactionLeftOpenIsRolledBackWithTheWrite(): void
    {
        TxScenario::$nestOn = BeforeSaveEvent::class;
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;
        TxScenario::$unnestOn = AfterCreateEvent::class;
        TxScenario::$throwOn = BeforeCreateEvent::class;

        $response = $this->handle('/txWidgets', 'POST', $this->widget('third'));

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['seeded'], $this->notes());
        self::assertSame(['first', 'second'], $this->widgetNames());
        $this->assertNoTransactionOpen();
    }

    #[Test]
    public function anAtomicBatchStillRollsBackAsOne(): void
    {
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;
        TxScenario::$throwOn = BeforeCreateEvent::class;
        TxScenario::$throwForName = 'boom';

        $response = $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'txWidgets', 'attributes' => ['name' => 'third']]],
            ['op' => 'add', 'data' => ['type' => 'txWidgets', 'attributes' => ['name' => 'boom']]],
        ]);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['seeded'], $this->notes());
        self::assertSame(['first', 'second'], $this->widgetNames());
        $this->assertNoTransactionOpen();
    }

    #[Test]
    public function anAtomicBatchStillCommitsAsOne(): void
    {
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;

        $response = $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'txWidgets', 'attributes' => ['name' => 'third']]],
            ['op' => 'add', 'data' => ['type' => 'txWidgets', 'attributes' => ['name' => 'fourth']]],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['seeded', 'by BeforeSaveEvent', 'by BeforeSaveEvent'], $this->notes());
        self::assertSame(['first', 'second', 'third', 'fourth'], $this->widgetNames());
        $this->assertNoTransactionOpen();
    }

    /**
     * @param list<string> $ids
     */
    protected function assertRelationshipWriteRollsBack(string $method, string $widget, array $ids): void
    {
        TxScenario::$sideWriteOn = BeforeRelationshipMutateEvent::class;
        TxScenario::$throwOn = BeforeRelationshipCommitEvent::class;

        $response = $this->handle('/txWidgets/' . $widget . '/relationships/notes', $method, [
            'data' => \array_map(static fn(string $id): array => ['type' => 'txNotes', 'id' => $id], $ids),
        ]);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['seeded'], $this->notes());
        self::assertSame(['1'], $this->linkage('/txWidgets/1/relationships/notes'));
        self::assertSame([], $this->linkage('/txWidgets/2/relationships/notes'));
        $this->assertNoTransactionOpen();
    }

    /**
     * Asserts nothing the request opened is still open, on stores that can tell.
     */
    protected function assertNoTransactionOpen(): void {}

    /**
     * @param list<string>|null $notes
     *
     * @return array<string, mixed>
     */
    protected function widget(string $name, ?string $id = null, ?array $notes = null): array
    {
        $data = ['type' => 'txWidgets', 'attributes' => ['name' => $name]];
        if ($id !== null) {
            $data['id'] = $id;
        }
        if ($notes !== null) {
            $data['relationships'] = ['notes' => ['data' => \array_map(static fn(string $note): array => ['type' => 'txNotes', 'id' => $note], $notes)]];
        }

        return ['data' => $data];
    }

    /**
     * @param list<array<string, mixed>> $operations
     */
    protected function atomic(array $operations): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $ext = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $request = Request::create('/operations', 'POST', server: ['CONTENT_TYPE' => $ext, 'HTTP_ACCEPT' => $ext], content: \json_encode(['atomic:operations' => $operations], \JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $this->restoreHandlers();

        return $response;
    }

    /**
     * @return list<string>
     */
    protected function notes(): array
    {
        return $this->column('/txNotes', 'body');
    }

    /**
     * @return list<string>
     */
    protected function widgetNames(): array
    {
        return $this->column('/txWidgets', 'name');
    }

    /**
     * @return list<string>
     */
    protected function linkage(string $path): array
    {
        $this->detachPersistedState();
        $data = $this->decode($this->handle($path))['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            self::assertIsString($identifier['id'] ?? null);
            $ids[] = $identifier['id'];
        }
        \sort($ids);

        return $ids;
    }

    /**
     * Lets a store that caches loaded rows forget them, so a read after a write sees what was
     * stored.
     */
    protected function detachPersistedState(): void {}

    /**
     * @return list<string>
     */
    private function column(string $path, string $attribute): array
    {
        $this->detachPersistedState();
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        $values = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $attributes = $resource['attributes'] ?? null;
            self::assertIsArray($attributes);
            self::assertIsString($attributes[$attribute] ?? null);
            $values[] = $attributes[$attribute];
        }

        return $values;
    }
}
