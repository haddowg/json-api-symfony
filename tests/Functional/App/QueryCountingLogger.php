<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use Psr\Log\AbstractLogger;

/**
 * A minimal PSR-3 logger that counts the SQL statements Doctrine's DBAL
 * {@see \Doctrine\DBAL\Logging\Middleware} reports — the query-count probe the
 * Doctrine relation-count suite uses to prove the `?withCount` batch issues ONE
 * grouped count per relation across a page of parents, not one count per parent
 * (bundle ADR 0052).
 *
 * The DBAL logging middleware logs `"Executing query: …"` (and a `"statement"`
 * variant for prepared statements) at debug level once per executed statement, so
 * counting those messages counts the queries. {@see reset()} zeroes the counter
 * around the request under probe; {@see queryCount()} reads it back.
 */
final class QueryCountingLogger extends AbstractLogger
{
    private int $queries = 0;

    /**
     * @var list<string>
     */
    private array $statements = [];

    public function log(mixed $level, \Stringable|string $message, array $context = []): void
    {
        $text = (string) $message;
        if (\str_starts_with($text, 'Executing query') || \str_starts_with($text, 'Executing statement')) {
            ++$this->queries;
            $sql = $context['sql'] ?? null;
            $this->statements[] = \is_string($sql) ? $sql : $text;
        }
    }

    public function reset(): void
    {
        $this->queries = 0;
        $this->statements = [];
    }

    public function queryCount(): int
    {
        return $this->queries;
    }

    /**
     * The SQL of every counted statement (for asserting which queries ran).
     *
     * @return list<string>
     */
    public function statements(): array
    {
        return $this->statements;
    }
}
