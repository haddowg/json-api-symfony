<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataPersister;

use haddowg\JsonApiBundle\DataPersister\WriteTransactionContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The per-request write-transaction context (the Atomic Operations seam): a flag
 * + a FIFO post-commit-hook queue the executor drives. On the single-op write
 * path it stays inactive, so the {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler}
 * fires its After* hooks inline; the executor activates it for a batch so the
 * After* dispatch is enqueued and drained after the transaction commits.
 */
final class WriteTransactionContextTest extends TestCase
{
    #[Test]
    public function itIsInactiveByDefault(): void
    {
        self::assertFalse((new WriteTransactionContext())->isActive());
    }

    #[Test]
    public function activateThenDeactivateTogglesTheFlag(): void
    {
        $context = new WriteTransactionContext();

        $context->activate();
        self::assertTrue($context->isActive());

        $context->deactivate();
        self::assertFalse($context->isActive());
    }

    #[Test]
    public function drainRunsEnqueuedCallablesInFifoOrderThenClears(): void
    {
        $context = new WriteTransactionContext();

        $log = [];
        $context->enqueuePostCommit(static function () use (&$log): void {
            $log[] = 'first';
        });
        $context->enqueuePostCommit(static function () use (&$log): void {
            $log[] = 'second';
        });
        $context->enqueuePostCommit(static function () use (&$log): void {
            $log[] = 'third';
        });

        // Nothing runs until drain.
        self::assertSame([], $log);

        $context->drain();

        // FIFO: enqueue order is run order.
        self::assertSame(['first', 'second', 'third'], $log);

        // The queue is cleared — a second drain runs nothing.
        $context->drain();
        self::assertSame(['first', 'second', 'third'], $log);
    }

    #[Test]
    public function deactivateDiscardsAnUndrainedQueue(): void
    {
        $context = new WriteTransactionContext();
        $context->activate();

        $ran = false;
        $context->enqueuePostCommit(static function () use (&$ran): void {
            $ran = true;
        });

        // The executor deactivates without draining on a rollback — the queued
        // hooks of a rolled-back batch never fire.
        $context->deactivate();
        $context->drain();

        self::assertFalse($ran);
        self::assertFalse($context->isActive());
    }

    #[Test]
    public function drainWithAnErrorHandlerCatchesAThrowingHookAndRunsTheRest(): void
    {
        // The best-effort post-commit drain (Slice D, bundle ADR 0088): the batch has
        // already durably committed by the time the drain runs, so a hook that throws
        // must NOT abort the drain — the error is handed to the $onError handler and the
        // remaining hooks still run. (With no handler the first exception propagates,
        // covered by the inline single-op path which never drains.)
        $context = new WriteTransactionContext();

        $log = [];
        $context->enqueuePostCommit(static function () use (&$log): void {
            $log[] = 'first';
        });
        $context->enqueuePostCommit(static function (): void {
            throw new \RuntimeException('hook boom');
        });
        $context->enqueuePostCommit(static function () use (&$log): void {
            $log[] = 'third';
        });

        $caught = [];
        $context->drain(static function (\Throwable $throwable) use (&$caught): void {
            $caught[] = $throwable->getMessage();
        });

        // The throwing hook did not stop the drain: the first and third hooks ran, and
        // the exception was handed to the handler rather than propagating.
        self::assertSame(['first', 'third'], $log);
        self::assertSame(['hook boom'], $caught);

        // The queue is cleared.
        $context->drain();
        self::assertSame(['first', 'third'], $log);
    }

    #[Test]
    public function drainWithoutAnErrorHandlerPropagatesAThrowingHook(): void
    {
        // The legacy (single-op) path: with no $onError handler the first exception
        // propagates, exactly as before — the seam is opt-in to the executor's drain.
        $context = new WriteTransactionContext();
        $context->enqueuePostCommit(static function (): void {
            throw new \RuntimeException('inline boom');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('inline boom');

        $context->drain();
    }

    #[Test]
    public function resetClearsTheFlagAndTheQueue(): void
    {
        $context = new WriteTransactionContext();
        $context->activate();

        $ran = false;
        $context->enqueuePostCommit(static function () use (&$ran): void {
            $ran = true;
        });

        // The kernel.reset hook between requests in a long-lived container.
        $context->reset();

        self::assertFalse($context->isActive());

        $context->drain();
        self::assertFalse($ran);
    }
}
