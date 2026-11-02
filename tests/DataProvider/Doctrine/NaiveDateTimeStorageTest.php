<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataProvider\Doctrine;

use Doctrine\DBAL\Types\Types;
use haddowg\JsonApiBundle\DataProvider\Doctrine\NaiveDateTimeStorage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NaiveDateTimeStorageTest extends TestCase
{
    private string $previousTimezone;

    protected function setUp(): void
    {
        $this->previousTimezone = \date_default_timezone_get();
        \date_default_timezone_set('America/New_York');
    }

    protected function tearDown(): void
    {
        \date_default_timezone_set($this->previousTimezone);
    }

    #[Test]
    public function aNaiveDateTimeMovesIntoTheDefaultTimezone(): void
    {
        $storage = new NaiveDateTimeStorage();
        $bound = new \DateTimeImmutable('2026-10-01T16:00:00Z');

        foreach ([Types::DATETIME_IMMUTABLE, Types::DATETIME_MUTABLE] as $type) {
            $stored = $storage->toStorage($bound, $type);
            self::assertInstanceOf(\DateTimeImmutable::class, $stored);
            self::assertSame('2026-10-01 12:00:00', $stored->format('Y-m-d H:i:s'));
        }
    }

    #[Test]
    public function theZoneIsReadWhenEachValueConverts(): void
    {
        $storage = new NaiveDateTimeStorage();
        \date_default_timezone_set('Europe/Paris');

        $stored = $storage->toStorage(new \DateTimeImmutable('2026-10-01T16:00:00Z'), Types::DATETIME_IMMUTABLE);
        self::assertInstanceOf(\DateTimeInterface::class, $stored);
        self::assertSame('2026-10-01 18:00:00', $stored->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function aMutableDateTimeIsCopiedNotMoved(): void
    {
        $value = new \DateTime('2026-10-01T16:00:00Z');
        $stored = (new NaiveDateTimeStorage())->toStorage($value, Types::DATETIME_MUTABLE);

        self::assertInstanceOf(\DateTime::class, $stored);
        self::assertNotSame($value, $stored);
        self::assertSame('2026-10-01 12:00:00', $stored->format('Y-m-d H:i:s'));
        self::assertSame('+00:00', $value->format('P'));
    }

    #[Test]
    public function aZonedOrDateOnlyColumnDoesNotConvert(): void
    {
        $storage = new NaiveDateTimeStorage();
        $bound = new \DateTimeImmutable('2026-10-01T00:00:00Z');

        foreach ([Types::DATETIMETZ_IMMUTABLE, Types::DATE_IMMUTABLE, Types::TIME_IMMUTABLE, Types::STRING, null] as $type) {
            self::assertSame($bound, $storage->toStorage($bound, $type));
        }
    }

    #[Test]
    public function aDateColumnBindsWithItsOwnTypeAndTheBoundsOwnDay(): void
    {
        $storage = new NaiveDateTimeStorage();
        $bound = new \DateTimeImmutable('2026-10-01T00:00:00Z');

        [$value, $type] = $storage->parameter($bound, Types::DATE_IMMUTABLE);
        self::assertSame(Types::DATE_IMMUTABLE, $type);
        self::assertInstanceOf(\DateTimeImmutable::class, $value);
        self::assertSame('2026-10-01', $value->format('Y-m-d'));

        [$value, $type] = $storage->parameter($bound, Types::DATE_MUTABLE);
        self::assertSame(Types::DATE_MUTABLE, $type);
        self::assertInstanceOf(\DateTime::class, $value);
        self::assertSame('2026-10-01', $value->format('Y-m-d'));
    }

    #[Test]
    public function aNaiveColumnBindsTheConvertedValueWithItsOwnType(): void
    {
        $storage = new NaiveDateTimeStorage();
        $bound = new \DateTimeImmutable('2026-10-01T16:00:00Z');

        [$value, $type] = $storage->parameter($bound, Types::DATETIME_IMMUTABLE);
        self::assertSame(Types::DATETIME_IMMUTABLE, $type);
        self::assertInstanceOf(\DateTimeImmutable::class, $value);
        self::assertSame('2026-10-01 12:00:00', $value->format('Y-m-d H:i:s'));

        [$value, $type] = $storage->parameter($bound, Types::DATETIME_MUTABLE);
        self::assertSame(Types::DATETIME_MUTABLE, $type);
        self::assertInstanceOf(\DateTime::class, $value);
        self::assertSame('2026-10-01 12:00:00', $value->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function aZonedColumnBindsTheValueWithItsOffsetAndItsOwnType(): void
    {
        $storage = new NaiveDateTimeStorage();
        $bound = new \DateTimeImmutable('2026-10-01T16:00:00Z');

        [$value, $type] = $storage->parameter($bound, Types::DATETIMETZ_IMMUTABLE);
        self::assertSame(Types::DATETIMETZ_IMMUTABLE, $type);
        self::assertInstanceOf(\DateTimeImmutable::class, $value);
        self::assertSame('2026-10-01T16:00:00+00:00', $value->format(\DateTimeInterface::ATOM));

        [$value, $type] = $storage->parameter($bound, Types::DATETIMETZ_MUTABLE);
        self::assertSame(Types::DATETIMETZ_MUTABLE, $type);
        self::assertInstanceOf(\DateTime::class, $value);
        self::assertSame('2026-10-01T16:00:00+00:00', $value->format(\DateTimeInterface::ATOM));
    }

    #[Test]
    public function aTimeOrNonDateColumnBindsWithAnInferredType(): void
    {
        $bound = new \DateTimeImmutable('2026-10-01T16:00:00Z');

        self::assertSame([$bound, null], (new NaiveDateTimeStorage())->parameter($bound, Types::TIME_IMMUTABLE));
        self::assertSame([$bound, null], (new NaiveDateTimeStorage())->parameter($bound, Types::STRING));
        self::assertSame([$bound, null], (new NaiveDateTimeStorage())->parameter($bound, null));
    }

    #[Test]
    public function aNonDateValueBindsUnchanged(): void
    {
        self::assertSame(['2026-10-01', null], (new NaiveDateTimeStorage())->parameter('2026-10-01', Types::DATETIME_IMMUTABLE));
    }
}
