<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataPersister;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataPersister\AfterCommit;
use haddowg\JsonApiBundle\DataPersister\DataPersisterInterface;
use haddowg\JsonApiBundle\DataPersister\TransactionalDataPersisterInterface;
use haddowg\JsonApiBundle\DataPersister\WriteTransaction;
use haddowg\JsonApiBundle\DataPersister\WriteTransactionContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Callbacks registered on {@see AfterCommit} run once the outermost write scope commits, in
 * order, and are dropped with a scope that rolls back. {@see WriteTransaction::run()} opens a
 * scope for every write, with or without a transaction.
 */
final class AfterCommitTest extends TestCase
{
    /** @var list<string> */
    private array $log = [];

    #[Test]
    public function aCallbackRegisteredOutsideAnyWriteRunsAtOnce(): void
    {
        $afterCommit = new AfterCommit();

        $afterCommit->register($this->record('now'));

        self::assertSame(['now'], $this->log);
    }

    #[Test]
    public function callbacksWaitForTheCommitAndRunInRegistrationOrder(): void
    {
        $afterCommit = new AfterCommit();

        $afterCommit->open();
        $afterCommit->register($this->record('first'));
        $afterCommit->register($this->record('second'));
        self::assertSame([], $this->log);

        $afterCommit->commit();
        self::assertSame(['first', 'second'], $this->log);
    }

    #[Test]
    public function aDiscardedScopeRunsNothing(): void
    {
        $afterCommit = new AfterCommit();

        $afterCommit->open();
        $afterCommit->register($this->record('dropped'));
        $afterCommit->discard();

        self::assertSame([], $this->log);
        $afterCommit->register($this->record('later'));
        self::assertSame(['later'], $this->log);
    }

    #[Test]
    public function aNestedScopeHandsItsCallbacksToTheOuterOneOnCommit(): void
    {
        $afterCommit = new AfterCommit();

        $afterCommit->open();
        $afterCommit->register($this->record('outer'));
        $afterCommit->open();
        $afterCommit->register($this->record('inner'));
        $afterCommit->commit();
        self::assertSame([], $this->log, 'An inner commit does not run anything.');

        $afterCommit->register($this->record('outer again'));
        $afterCommit->commit();
        self::assertSame(['outer', 'inner', 'outer again'], $this->log);
    }

    #[Test]
    public function aNestedScopeThatRollsBackDropsOnlyItsOwnCallbacks(): void
    {
        $afterCommit = new AfterCommit();

        $afterCommit->open();
        $afterCommit->register($this->record('outer'));
        $afterCommit->open();
        $afterCommit->register($this->record('inner'));
        $afterCommit->discard();
        $afterCommit->commit();

        self::assertSame(['outer'], $this->log);
    }

    #[Test]
    public function aCallbackThatThrowsIsLoggedAndTheRestStillRun(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string, mixed}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context['exception'] ?? null];
            }
        };
        $afterCommit = new AfterCommit($logger);
        $failure = new \RuntimeException('queue down');

        $afterCommit->open();
        $afterCommit->register(static function () use ($failure): void {
            throw $failure;
        });
        $afterCommit->register($this->record('second'));
        $afterCommit->commit();

        self::assertSame(['second'], $this->log);
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0][0]);
        self::assertSame($failure, $logger->records[0][2]);
    }

    #[Test]
    public function aCallbackRegisteredWhileTheCallbacksRunRunsAtOnce(): void
    {
        $afterCommit = new AfterCommit();

        $afterCommit->open();
        $afterCommit->register(function () use ($afterCommit): void {
            $this->log[] = 'first';
            $afterCommit->register($this->record('from first'));
        });
        $afterCommit->register($this->record('second'));
        $afterCommit->commit();

        self::assertSame(['first', 'from first', 'second'], $this->log);
    }

    #[Test]
    public function resetDropsEveryOpenScope(): void
    {
        $afterCommit = new AfterCommit();

        $afterCommit->open();
        $afterCommit->open();
        $afterCommit->register($this->record('left behind'));
        $afterCommit->reset();

        $afterCommit->register($this->record('next request'));
        self::assertSame(['next request'], $this->log);
    }

    #[Test]
    public function aTransactionalWriteRunsItsCallbacksAfterTheCommit(): void
    {
        $afterCommit = new AfterCommit();
        $persister = $this->persister();

        $result = WriteTransaction::run($persister, new WriteTransactionContext(), function () use ($afterCommit): string {
            $afterCommit->register($this->record('callback'));
            $this->log[] = 'write';

            return 'done';
        }, $afterCommit);

        self::assertSame('done', $result);
        self::assertSame(['begin', 'write', 'commit', 'callback'], $this->log);
    }

    #[Test]
    public function aWriteThatThrowsRollsBackAndDropsItsCallbacks(): void
    {
        $afterCommit = new AfterCommit();

        try {
            WriteTransaction::run($this->persister(), null, function () use ($afterCommit): void {
                $afterCommit->register($this->record('callback'));

                throw new \DomainException('refused');
            }, $afterCommit);
        } catch (\DomainException) {
        }

        self::assertSame(['begin', 'rollback'], $this->log);
        $afterCommit->register($this->record('outside'));
        self::assertSame(['begin', 'rollback', 'outside'], $this->log);
    }

    #[Test]
    public function aFailedCommitDropsTheCallbacks(): void
    {
        $afterCommit = new AfterCommit();

        try {
            WriteTransaction::run($this->persister(commitThrows: true), null, function () use ($afterCommit): void {
                $afterCommit->register($this->record('callback'));
            }, $afterCommit);
            self::fail('The commit threw.');
        } catch (\DomainException) {
        }

        self::assertSame(['begin', 'commit', 'rollback'], $this->log);
    }

    #[Test]
    public function aWriteWithoutATransactionRunsItsCallbacksOnceItReturns(): void
    {
        $afterCommit = new AfterCommit();

        WriteTransaction::run(null, null, function () use ($afterCommit): void {
            $afterCommit->register($this->record('callback'));
            $this->log[] = 'write';
        }, $afterCommit);

        self::assertSame(['write', 'callback'], $this->log);
    }

    #[Test]
    public function aWriteWithoutATransactionThatThrowsDropsItsCallbacks(): void
    {
        $afterCommit = new AfterCommit();

        try {
            WriteTransaction::run(null, null, function () use ($afterCommit): void {
                $afterCommit->register($this->record('callback'));

                throw new \DomainException('refused');
            }, $afterCommit);
        } catch (\DomainException) {
        }

        self::assertSame([], $this->log);
    }

    #[Test]
    public function aWriteInsideABatchWaitsForTheBatchToCommit(): void
    {
        $afterCommit = new AfterCommit();
        $context = new WriteTransactionContext();
        $context->activate();
        $afterCommit->open();

        WriteTransaction::run($this->persister(), $context, function () use ($afterCommit): void {
            $afterCommit->register($this->record('callback'));
        }, $afterCommit);

        self::assertSame([], $this->log, 'The batch owns the transaction, so the write neither commits nor runs its callbacks.');
        $afterCommit->commit();
        self::assertSame(['callback'], $this->log);
    }

    #[Test]
    public function nestedWritesRunTheirCallbacksOnlyWhenTheOutermostCommits(): void
    {
        $afterCommit = new AfterCommit();
        $persister = $this->persister();

        WriteTransaction::run($persister, null, function () use ($afterCommit, $persister): void {
            WriteTransaction::run($persister, null, function () use ($afterCommit): void {
                $afterCommit->register($this->record('inner'));
            }, $afterCommit);
            $this->log[] = 'inner returned';
        }, $afterCommit);

        self::assertSame(['begin', 'begin', 'commit', 'inner returned', 'commit', 'inner'], $this->log);
    }

    /**
     * @return \Closure(): void
     */
    private function record(string $entry): \Closure
    {
        return function () use ($entry): void {
            $this->log[] = $entry;
        };
    }

    private function persister(bool $commitThrows = false): DataPersisterInterface&TransactionalDataPersisterInterface
    {
        $record = function (string $entry): void {
            $this->log[] = $entry;
        };

        return new class ($record, $commitThrows) implements DataPersisterInterface, TransactionalDataPersisterInterface {
            /**
             * @param \Closure(string): void $record
             */
            public function __construct(private readonly \Closure $record, private readonly bool $commitThrows) {}

            public function supports(string $type): bool
            {
                return true;
            }

            public function instantiate(string $type): object
            {
                return new \stdClass();
            }

            public function create(string $type, object $entity): object
            {
                return $entity;
            }

            public function update(string $type, object $entity): object
            {
                return $entity;
            }

            public function delete(string $type, object $entity): void {}

            public function mutateRelationship(string $type, object $entity, RelationInterface $relation, ToOneRelationship|ToManyRelationship $linkage, Mode $mode, bool $flush = true): object
            {
                return $entity;
            }

            public function beginTransaction(): void
            {
                ($this->record)('begin');
            }

            public function commit(): void
            {
                ($this->record)('commit');
                if ($this->commitThrows) {
                    throw new \DomainException('commit failed');
                }
            }

            public function rollback(): void
            {
                ($this->record)('rollback');
            }
        };
    }
}
