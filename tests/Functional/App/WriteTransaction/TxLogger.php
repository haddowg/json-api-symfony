<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction;

use Psr\Log\AbstractLogger;

/**
 * Keeps the messages logged at error level in {@see TxScenario::$errors}.
 */
final class TxLogger extends AbstractLogger
{
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($level === 'error') {
            TxScenario::$errors[] = (string) $message;
        }
    }
}
