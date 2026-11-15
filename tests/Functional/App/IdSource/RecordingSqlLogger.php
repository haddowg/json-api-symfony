<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdSource;

use Psr\Log\AbstractLogger;

/**
 * Keeps every SQL statement DBAL's logging middleware reports, in order.
 */
final class RecordingSqlLogger extends AbstractLogger
{
    /** @var list<string> */
    public static array $statements = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $sql = $context['sql'] ?? null;
        if (\is_string($sql)) {
            self::$statements[] = $sql;
        }
    }
}
