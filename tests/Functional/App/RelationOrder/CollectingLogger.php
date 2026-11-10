<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder;

use Psr\Log\AbstractLogger;

/**
 * Keeps every message logged, so a test can read what warm-up reported.
 */
final class CollectingLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string}>
     */
    private array $records = [];

    public function reset(): void
    {
        $this->records = [];
    }

    /**
     * The messages logged at `$level`, in order.
     *
     * @return list<string>
     */
    public function messages(string $level): array
    {
        $messages = [];
        foreach ($this->records as $record) {
            if ($record['level'] === $level) {
                $messages[] = $record['message'];
            }
        }

        return $messages;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message];
    }
}
