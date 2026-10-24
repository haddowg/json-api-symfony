<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Keeps the interpolated warnings logged, so a test can read what the warmer reported.
 */
final class WarningLogger extends AbstractLogger
{
    /** @var list<string> */
    public static array $warnings = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($level !== LogLevel::WARNING) {
            return;
        }

        $replace = [];
        foreach ($context as $key => $value) {
            if (\is_string($value)) {
                $replace['{' . $key . '}'] = $value;
            }
        }
        self::$warnings[] = \strtr((string) $message, $replace);
    }
}
