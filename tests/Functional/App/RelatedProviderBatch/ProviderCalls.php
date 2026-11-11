<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedProviderBatch;

/**
 * Counts the calls the run providers answer, by method and type.
 */
final class ProviderCalls
{
    /**
     * @var array<string, int>
     */
    private static array $calls = [];

    public static function record(string $method, string $type): void
    {
        $key = $method . ' ' . $type;
        self::$calls[$key] = (self::$calls[$key] ?? 0) + 1;
    }

    public static function count(string $method, string $type): int
    {
        return self::$calls[$method . ' ' . $type] ?? 0;
    }

    public static function reset(): void
    {
        self::$calls = [];
    }
}
