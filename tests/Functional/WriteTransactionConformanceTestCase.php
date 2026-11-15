<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\DataPersister\AfterCommit;
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
use Symfony\Contracts\Service\ResetInterface;

/**
 * Every single write runs in one transaction, from its first write step through the
 * persister's write: a before hook writes a note through the notes persister (a flush, on
 * Doctrine), a later step throws, and neither the note nor the write survives. A writing
 * action runs the same way, a GET action does not, and an Atomic Operations batch keeps its
 * own transaction. Callbacks registered on {@see AfterCommit} during a write run once it
 * commits, ahead of the after hooks, and never for a write that rolls back. Runs on the
 * Doctrine and in-memory kernels.
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

    #[Test]
    public function aCreateRunsItsCallbacksAfterTheCommitAndBeforeTheAfterHooks(): void
    {
        TxScenario::$afterCommitOn = BeforeSaveEvent::class;

        $response = $this->handle('/txWidgets', 'POST', $this->widget('third'));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['BeforeSaveEvent', 'BeforeCreateEvent', 'after commit (BeforeSaveEvent)', 'AfterCreateEvent', 'AfterSaveEvent'], TxScenario::$log);
        $this->assertCallbacksRanCommitted(1);
    }

    #[Test]
    public function anUpdateRunsItsCallbacksAfterTheCommitAndBeforeTheAfterHooks(): void
    {
        TxScenario::$afterCommitOn = BeforeUpdateEvent::class;

        $response = $this->handle('/txWidgets/1', 'PATCH', $this->widget('renamed', id: '1'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['BeforeApplyUpdateEvent', 'BeforeSaveEvent', 'BeforeUpdateEvent', 'after commit (BeforeUpdateEvent)', 'AfterUpdateEvent', 'AfterSaveEvent'], TxScenario::$log);
        $this->assertCallbacksRanCommitted(1);
    }

    #[Test]
    public function aDeleteRunsItsCallbacksAfterTheCommitAndBeforeTheAfterHooks(): void
    {
        TxScenario::$afterCommitOn = BeforeDeleteEvent::class;

        $response = $this->handle('/txWidgets/2', 'DELETE');

        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['BeforeDeleteEvent', 'after commit (BeforeDeleteEvent)', 'AfterDeleteEvent'], TxScenario::$log);
        $this->assertCallbacksRanCommitted(1);
    }

    #[Test]
    public function aRelationshipWriteRunsItsCallbacksAfterTheCommitAndBeforeTheAfterHooks(): void
    {
        TxScenario::$afterCommitOn = BeforeRelationshipMutateEvent::class;

        $response = $this->handle('/txWidgets/2/relationships/notes', 'POST', ['data' => [['type' => 'txNotes', 'id' => '1']]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['BeforeRelationshipMutateEvent', 'BeforeRelationshipCommitEvent', 'after commit (BeforeRelationshipMutateEvent)', 'AfterRelationshipMutateEvent'], TxScenario::$log);
        $this->assertCallbacksRanCommitted(1);
    }

    #[Test]
    public function aWritingActionRunsItsCallbacksAfterTheCommit(): void
    {
        TxScenario::$actionAfterCommit = true;
        TxScenario::$afterCommitOn = BeforeActionEvent::class;

        $response = $this->handle('/txWidgets/1/-actions/stamp', 'POST');

        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['BeforeActionEvent', 'AfterActionEvent', 'after commit (BeforeActionEvent)', 'after commit (stamp)'], TxScenario::$log);
        $this->assertCallbacksRanCommitted(2);
    }

    #[Test]
    public function aWritingActionThatThrowsRunsNoCallbacks(): void
    {
        TxScenario::$actionAfterCommit = true;
        TxScenario::$actionThrows = true;

        $response = $this->handle('/txWidgets/1/-actions/stamp', 'POST');

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['BeforeActionEvent'], TxScenario::$log);
    }

    #[Test]
    public function aWritingActionThatReturnsAnErrorCommitsAndRunsItsCallbacks(): void
    {
        TxScenario::$actionAfterCommit = true;
        TxScenario::$actionReturnsError = true;

        $response = $this->handle('/txWidgets/1/-actions/stamp', 'POST');

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['BeforeActionEvent', 'AfterActionEvent', 'after commit (stamp)'], TxScenario::$log);
        $this->assertCallbacksRanCommitted(1);
        self::assertSame(['seeded', 'stamped'], $this->notes());
    }

    #[Test]
    public function aGetActionRunsItsCallbacksOnceItReturns(): void
    {
        TxScenario::$actionAfterCommit = true;

        $response = $this->handle('/txWidgets/1/-actions/peek');

        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['BeforeActionEvent', 'AfterActionEvent', 'after commit (peek)'], TxScenario::$log);
    }

    #[Test]
    public function aGetActionThatThrowsRunsNoCallbacks(): void
    {
        TxScenario::$actionAfterCommit = true;
        TxScenario::$actionThrows = true;

        $response = $this->handle('/txWidgets/1/-actions/peek');

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['BeforeActionEvent'], TxScenario::$log);
    }

    #[Test]
    public function aPersisterThatAcceptsAWriteQueuesItsJobAfterTheCommit(): void
    {
        TxScenario::$accept = true;

        $response = $this->handle('/txWidgets', 'POST', $this->widget('third'));

        self::assertSame(202, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['BeforeSaveEvent', 'BeforeCreateEvent', 'dispatch job'], TxScenario::$log);
        $this->assertCallbacksRanCommitted(1);
    }

    #[Test]
    public function aWriteThatRollsBackRunsNoCallbacks(): void
    {
        TxScenario::$afterCommitOn = BeforeSaveEvent::class;
        TxScenario::$throwOn = BeforeCreateEvent::class;

        $response = $this->handle('/txWidgets', 'POST', $this->widget('third'));

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['BeforeSaveEvent', 'BeforeCreateEvent'], TxScenario::$log);

        // Nothing is left waiting for the next write.
        TxScenario::reset();
        self::assertSame(201, $this->handle('/txWidgets', 'POST', $this->widget('third'))->getStatusCode());
        self::assertSame(['BeforeSaveEvent', 'BeforeCreateEvent', 'AfterCreateEvent', 'AfterSaveEvent'], TxScenario::$log);
    }

    #[Test]
    public function aCallbackThatThrowsIsLoggedAndTheOthersStillRun(): void
    {
        TxScenario::$afterCommitOn = BeforeSaveEvent::class;
        TxScenario::$throwingCallback = true;

        $response = $this->handle('/txWidgets', 'POST', $this->widget('third'));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['BeforeSaveEvent', 'BeforeCreateEvent', 'after commit (BeforeSaveEvent)', 'AfterCreateEvent', 'AfterSaveEvent'], TxScenario::$log);
        self::assertCount(1, TxScenario::$errors);
        self::assertStringContainsString('after a write committed', TxScenario::$errors[0]);
        self::assertSame(['first', 'second', 'third'], $this->widgetNames());
    }

    #[Test]
    public function aCallbackRegisteredOutsideAWriteRunsAtOnce(): void
    {
        TxScenario::$afterCommitOn = AfterCreateEvent::class;

        $response = $this->handle('/txWidgets', 'POST', $this->widget('third'));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['BeforeSaveEvent', 'BeforeCreateEvent', 'AfterCreateEvent', 'after commit (AfterCreateEvent)', 'AfterSaveEvent'], TxScenario::$log);
        $this->assertCallbacksRanCommitted(1);
    }

    #[Test]
    public function anAtomicBatchRunsEveryCallbackOnceTheBatchCommits(): void
    {
        TxScenario::$afterCommitOn = BeforeSaveEvent::class;

        $response = $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'txWidgets', 'attributes' => ['name' => 'third']]],
            ['op' => 'add', 'data' => ['type' => 'txWidgets', 'attributes' => ['name' => 'fourth']]],
        ]);

        // Each operation's scope nests in the batch's, so nothing runs when an operation
        // returns; the callbacks run at the batch commit, ahead of the deferred after hooks.
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([
            'BeforeSaveEvent', 'BeforeCreateEvent',
            'BeforeSaveEvent', 'BeforeCreateEvent',
            'after commit (BeforeSaveEvent)', 'after commit (BeforeSaveEvent)',
            'AfterCreateEvent', 'AfterSaveEvent',
            'AfterCreateEvent', 'AfterSaveEvent',
        ], TxScenario::$log);
        $this->assertCallbacksRanCommitted(2);
    }

    #[Test]
    public function anAtomicBatchThatRollsBackRunsNoCallbacks(): void
    {
        TxScenario::$afterCommitOn = BeforeSaveEvent::class;
        TxScenario::$throwOn = BeforeCreateEvent::class;
        TxScenario::$throwForName = 'boom';

        $response = $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'txWidgets', 'attributes' => ['name' => 'third']]],
            ['op' => 'add', 'data' => ['type' => 'txWidgets', 'attributes' => ['name' => 'boom']]],
        ]);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['BeforeSaveEvent', 'BeforeCreateEvent', 'BeforeSaveEvent', 'BeforeCreateEvent'], TxScenario::$log);

        TxScenario::$log = [];
        $this->afterCommit()->register(static function (): void {
            TxScenario::$log[] = 'registered after the batch';
        });
        self::assertSame(['registered after the batch'], TxScenario::$log, 'The batch closed its scope.');
    }

    #[Test]
    public function aScopeLeftOpenIsResetBeforeTheNextRequest(): void
    {
        // A request that never closed its scope, in a worker.
        $afterCommit = $this->afterCommit();
        $afterCommit->open();
        $afterCommit->register(static function (): void {
            TxScenario::$log[] = 'left behind';
        });

        $resetter = static::getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        TxScenario::$afterCommitOn = BeforeSaveEvent::class;
        self::assertSame(201, $this->handle('/txWidgets', 'POST', $this->widget('third'))->getStatusCode());
        self::assertSame(['BeforeSaveEvent', 'BeforeCreateEvent', 'after commit (BeforeSaveEvent)', 'AfterCreateEvent', 'AfterSaveEvent'], TxScenario::$log);
    }

    /**
     * Asserts how many after-commit callbacks ran, each outside any transaction on stores
     * that can tell.
     */
    protected function assertCallbacksRanCommitted(int $count): void
    {
        self::assertCount($count, TxScenario::$callbackLevels);
    }

    protected function afterCommit(): AfterCommit
    {
        $afterCommit = static::getContainer()->get(AfterCommit::class);
        self::assertInstanceOf(AfterCommit::class, $afterCommit);

        return $afterCommit;
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
