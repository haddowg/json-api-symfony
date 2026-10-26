<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Freezes Symfony's global clock for every test before its kernel boots, so a value the
 * app derives from it, such as the `preview` link's `expiresAt`, is the same in every
 * render. Two renders compared in one test can't straddle a second boundary.
 */
trait PinsTheClock
{
    use ClockSensitiveTrait;

    /** The pinned instant. `expiresAt` is an hour later, the field's declared example. */
    public const string NOW = '2026-01-01T11:00:00+00:00';

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        parent::setUp();
    }
}
