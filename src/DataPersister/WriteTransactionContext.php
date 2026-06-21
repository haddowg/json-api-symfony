<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

use Symfony\Contracts\Service\ResetInterface;

/**
 * The per-request flag + post-commit-hook queue the Atomic Operations executor
 * drives so the lifecycle hooks of a batched write run AFTER the batch commits,
 * not inline with each operation.
 *
 * On the **single-operation** write path this context stays {@see isActive()
 * inactive}: the {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler}
 * fires each After* lifecycle event immediately and honours its response
 * replacement exactly as today (byte-for-byte unchanged). It is only the Atomic
 * Operations executor (the NEXT slice) that {@see activate()}s the context for the
 * duration of a batch, opens the transaction on the persisters, lets each
 * operation {@see enqueuePostCommit() enqueue} its After* dispatch instead of
 * firing it, and — after the transaction commits — {@see drain()}s the queue (or,
 * on a rollback, discards it by clearing the context without draining). The
 * batch's aggregate result is authoritative, so a deferred After* hook's response
 * replacement is intentionally inert under atomic.
 *
 * **Post-commit hooks run after the batch is durably committed, best-effort.** By
 * the time the queue {@see drain()}s, the batch's writes are already durable — so a
 * hook that throws does NOT fail the response and rolls nothing back (there is
 * nothing to undo). The executor drains with an error handler that logs each such
 * exception and lets the remaining hooks run; the batch still succeeds (bundle ADR
 * 0088).
 *
 * It is a stateful, **request-scoped** service: one instance per request, holding
 * the active flag and the FIFO queue of deferred callables for the in-flight
 * batch. It implements {@see ResetInterface} (auto-tagged `kernel.reset`) so a
 * long-lived container (a worker / messenger consumer reusing the kernel across
 * messages) clears the flag and queue between messages — a batch that somehow left
 * the context active or partly queued never leaks into the next request. Per-request
 * FPM never hits this (a fresh container starts inactive); the bundle targets
 * worker-capable architectures, so the reset is load-bearing.
 */
final class WriteTransactionContext implements ResetInterface
{
    private bool $active = false;

    /**
     * @var list<callable(): void>
     */
    private array $postCommit = [];

    /**
     * Whether a batch is in flight: `true` only while the executor has
     * {@see activate()}d the context. On the single-op path it is always `false`,
     * so the handler fires its After* hooks inline.
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Marks a batch as in flight (the executor wraps the batch in
     * {@see activate()}/{@see deactivate()}). While active the handler enqueues its
     * After* dispatch instead of firing it.
     */
    public function activate(): void
    {
        $this->active = true;
    }

    /**
     * Ends the in-flight batch and clears any not-yet-drained queue — the
     * executor's clean-up after committing-and-draining OR after a rollback (where
     * the queue is discarded undrained, so a rolled-back batch fires no After*
     * hooks). Safe to call unconditionally.
     */
    public function deactivate(): void
    {
        $this->active = false;
        $this->postCommit = [];
    }

    /**
     * Enqueues a callable to run after the batch's transaction commits. The
     * handler enqueues its After* dispatch here while the context is active; the
     * executor {@see drain()}s the queue post-commit.
     *
     * @param callable(): void $callback
     */
    public function enqueuePostCommit(callable $callback): void
    {
        $this->postCommit[] = $callback;
    }

    /**
     * Runs every enqueued post-commit callable in FIFO order, then clears the
     * queue — the executor calls this once the batch's transaction has committed,
     * so the deferred After* hooks observe the durably-persisted state. On a
     * rollback the executor never drains (it {@see deactivate()}s instead), so the
     * hooks of a rolled-back batch never run.
     *
     * The drain is **best-effort**: the batch is already durably committed by the
     * time it runs, so a throwing post-commit hook must NOT turn a successful batch
     * into a failure (and there is nothing to roll back — the data stands). When an
     * `$onError` handler is given, each hook's exception is passed to it and the
     * remaining hooks still run; with no handler the legacy behaviour holds (the
     * exception propagates), so the single-op write path — which never drains — is
     * unaffected. The executor supplies a handler that logs each exception.
     *
     * @param ?callable(\Throwable): void $onError invoked with each hook's exception
     *                                             (the remaining hooks then run); null
     *                                             lets the first exception propagate
     */
    public function drain(?callable $onError = null): void
    {
        $callbacks = $this->postCommit;
        $this->postCommit = [];

        foreach ($callbacks as $callback) {
            if ($onError === null) {
                $callback();

                continue;
            }

            try {
                $callback();
            } catch (\Throwable $throwable) {
                $onError($throwable);
            }
        }
    }

    /**
     * Clears the active flag and the deferred queue between requests in a
     * long-lived container (the `kernel.reset` hook), so no request inherits a
     * previous one's in-flight batch state.
     */
    public function reset(): void
    {
        $this->active = false;
        $this->postCommit = [];
    }
}
