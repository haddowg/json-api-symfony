<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone;

/**
 * Three shifts as local New York wall-clock times. Row 1 starts at 16:00Z, row 2 at
 * 14:00Z and row 3 at 13:00Z the next day.
 */
final class ShiftFixtures
{
    public const string ZONE = 'America/New_York';

    /**
     * @return array<int, array{startsAt: string, day: string}>
     */
    public static function data(): array
    {
        return [
            1 => ['startsAt' => '2026-10-01 12:00:00', 'day' => '2026-10-01'],
            2 => ['startsAt' => '2026-10-01 10:00:00', 'day' => '2026-09-30'],
            3 => ['startsAt' => '2026-10-02 09:00:00', 'day' => '2026-10-02'],
        ];
    }

    public static function at(string $localTime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($localTime, new \DateTimeZone(self::ZONE));
    }
}
