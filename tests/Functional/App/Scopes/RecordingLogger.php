<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Scopes;

use Psr\Log\AbstractLogger;

/**
 * Keeps the warnings it is given, so a test can see the enforcer report a stale table.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $warnings = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($level !== 'warning') {
            return;
        }

        $replace = [];
        foreach ($context as $key => $value) {
            $replace['{' . $key . '}'] = \is_scalar($value) ? (string) $value : '';
        }

        $this->warnings[] = \strtr((string) $message, $replace);
    }
}
