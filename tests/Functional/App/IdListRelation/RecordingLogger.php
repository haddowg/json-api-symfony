<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdListRelation;

use Psr\Log\AbstractLogger;

/**
 * Keeps the throwables the exception listener logs, so a test can see why a request
 * answered 500 without a debug kernel.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<\Throwable> */
    private static array $exceptions = [];

    public static function reset(): void
    {
        self::$exceptions = [];
    }

    public static function firstException(): ?\Throwable
    {
        return self::$exceptions[0] ?? null;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $exception = $context['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            self::$exceptions[] = $exception;
        }
    }
}
