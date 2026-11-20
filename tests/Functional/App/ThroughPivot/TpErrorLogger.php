<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Keeps the errors it is given, so a test can see a declaration fault logged.
 */
final class TpErrorLogger extends AbstractLogger
{
    /**
     * @var list<string>
     */
    public array $errors = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (\in_array($level, [LogLevel::ERROR, LogLevel::CRITICAL], true)) {
            $this->errors[] = (string) $message;
        }
    }
}
