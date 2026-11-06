<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Http;

use haddowg\JsonApiBundle\Http\SunsetDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SunsetDateTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dates(): iterable
    {
        yield 'IMF-fixdate' => ['Sat, 31 Dec 2050 23:59:59 GMT', '2050-12-31T23:59:59+00:00'];
        yield 'IMF-fixdate with the wrong day name' => ['Mon, 31 Dec 2050 23:59:59 GMT', '2050-12-31T23:59:59+00:00'];
        yield 'RFC 850' => ['Sunday, 06-Nov-94 08:49:37 GMT', '1994-11-06T08:49:37+00:00'];
        yield 'asctime' => ['Sun Nov  6 08:49:37 1994', '1994-11-06T08:49:37+00:00'];
        yield 'structured-field date' => ['@1924991999', '2030-12-31T23:59:59+00:00'];
        yield 'ISO 8601 date' => ['2051-06-30', '2051-06-30T00:00:00+00:00'];
        yield 'ISO 8601 date-time with an offset' => ['2051-06-30T10:00:00+02:00', '2051-06-30T10:00:00+02:00'];
    }

    #[Test]
    #[DataProvider('dates')]
    public function aDateIsRead(string $value, string $expected): void
    {
        self::assertSame($expected, SunsetDate::parse($value)?->format(\DATE_ATOM));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function notDates(): iterable
    {
        yield 'null' => [null];
        yield 'blank' => ['  '];
        yield 'a relative phrase' => ['+1 year'];
        yield 'an impossible HTTP-date' => ['Sat, 31 Feb 2050 23:59:59 GMT'];
        yield 'an impossible ISO date' => ['2050-02-30'];
        yield 'prose' => ['next spring'];
    }

    #[Test]
    #[DataProvider('notDates')]
    public function anythingElseIsNoDate(?string $value): void
    {
        self::assertNull(SunsetDate::parse($value));
    }
}
