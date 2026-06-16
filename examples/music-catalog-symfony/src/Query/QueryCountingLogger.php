<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Query;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * A PSR-3 logger that counts the SQL the Doctrine DBAL
 * {@see \Doctrine\DBAL\Logging\Middleware} emits, so the
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Tests\IncludePreloadTest} can
 * assert a request issues a *bounded* number of queries (≈ one per include level)
 * rather than N+1.
 *
 * The DBAL logging middleware logs every executed query/statement at `debug` level
 * with a message beginning `Executing query` / `Executing statement`; this counter
 * tallies exactly those, ignoring the connect/transaction lifecycle log lines so the
 * count reflects real round-trips to the database.
 *
 * It is wired as a `doctrine.middleware` logger purely in the example app (a test
 * affordance), so DoctrineBundle wraps the driver with the logging middleware at
 * connection construction; a production app would not register it. The test
 * {@see reset()}s the counter after the schema/seed setup, then reads {@see count()}.
 */
final class QueryCountingLogger extends AbstractLogger implements LoggerInterface
{
    private int $count = 0;

    /**
     * @var list<string>
     */
    private array $queries = [];

    /**
     * @param mixed             $level
     * @param string|\Stringable $message
     * @param array<mixed>      $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $message = (string) $message;
        if (\str_starts_with($message, 'Executing query') || \str_starts_with($message, 'Executing statement')) {
            ++$this->count;
            $sql = $context['sql'] ?? null;
            $this->queries[] = \is_string($sql) ? $sql : $message;
        }
    }

    public function reset(): void
    {
        $this->count = 0;
        $this->queries = [];
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * The number of counted queries whose SQL does NOT reference any of the given
     * (case-insensitive) substrings — so a test can isolate the include-load queries
     * from unrelated linkage-rendering of a to-many the example chose not to make
     * load-aware (e.g. the `tracks.playlists` pivot, which lazily loads per track
     * regardless of `?include`).
     *
     * @param list<string> $excludedSubstrings
     */
    public function countExcluding(array $excludedSubstrings): int
    {
        $count = 0;
        foreach ($this->queries as $sql) {
            $sql = \strtolower($sql);
            foreach ($excludedSubstrings as $needle) {
                if (\str_contains($sql, \strtolower($needle))) {
                    continue 2;
                }
            }
            ++$count;
        }

        return $count;
    }

    /**
     * The SQL of every counted query, in execution order — for diagnostics when an
     * assertion fails (so a failure message can show what actually ran).
     *
     * @return list<string>
     */
    public function queries(): array
    {
        return $this->queries;
    }
}
