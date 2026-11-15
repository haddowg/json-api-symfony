<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Runs code once the current write has committed: queue a message, send an email, call
 * another service, none of which a rollback could take back.
 *
 * Inject it anywhere a write runs, such as an action handler, a before hook or a persister,
 * and {@see register()} a callback. One registered during a single write runs when that
 * write's transaction commits, before the after hooks and the response, and is dropped if
 * the write rolls back. Inside an Atomic Operations batch it waits for the batch to commit.
 * A write that runs without a transaction, like a `GET` action or one on a persister that
 * can't transact, runs its callbacks once it returns without throwing. Registered outside
 * any write, a callback runs at once.
 *
 * Callbacks run in the order they were registered. One that throws is logged and the rest
 * still run: the write is already committed, so the response goes out as normal.
 *
 * It is one instance per request, and `kernel.reset` clears it between the requests a
 * worker serves.
 */
final class AfterCommit implements ResetInterface
{
    private readonly LoggerInterface $logger;

    /**
     * The callbacks of each open write, outermost first.
     *
     * @var list<list<callable(): mixed>>
     */
    private array $scopes = [];

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Runs the callback once the current write commits, or at once when no write is running.
     *
     * @param callable(): mixed $callback
     */
    public function register(callable $callback): void
    {
        $last = \array_key_last($this->scopes);
        if ($last === null) {
            $this->call($callback);

            return;
        }

        $this->scopes[$last][] = $callback;
    }

    /**
     * Opens a write's scope: callbacks registered from now on wait for its {@see commit()}.
     *
     * @internal driven by {@see WriteTransaction} and the Atomic Operations backend
     */
    public function open(): void
    {
        $this->scopes[] = [];
    }

    /**
     * Closes the innermost scope as committed. A nested scope hands its callbacks to the one
     * around it; the outermost runs them.
     *
     * @internal driven by {@see WriteTransaction} and the Atomic Operations backend
     */
    public function commit(): void
    {
        $callbacks = \array_pop($this->scopes) ?? [];

        $last = \array_key_last($this->scopes);
        if ($last !== null) {
            \array_push($this->scopes[$last], ...$callbacks);

            return;
        }

        foreach ($callbacks as $callback) {
            $this->call($callback);
        }
    }

    /**
     * Closes the innermost scope as rolled back, dropping its callbacks.
     *
     * @internal driven by {@see WriteTransaction} and the Atomic Operations backend
     */
    public function discard(): void
    {
        \array_pop($this->scopes);
    }

    public function reset(): void
    {
        $this->scopes = [];
    }

    /**
     * @param callable(): mixed $callback
     */
    private function call(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $throwable) {
            $this->logger->error(
                'A callback registered to run after a write committed threw; the write is committed, so the error is logged and the response goes out as normal.',
                ['exception' => $throwable],
            );
        }
    }
}
