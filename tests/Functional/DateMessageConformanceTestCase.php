<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A bad date reads as core's one message for its kind, whether it is an attribute write
 * or a date range bound: the attribute named by its path below `/data/attributes`, the
 * bound by its parameter, and the example the declared one or else the fixed one. The
 * same assertions run on the in-memory and Doctrine kernels.
 */
abstract class DateMessageConformanceTestCase extends JsonApiFunctionalTestCase
{
    /**
     * @return array<string, array{mixed, string, string}>
     */
    private static function badAttributes(): array
    {
        return [
            'startsAt' => ['last year', 'startsAt is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.', '/data/attributes/startsAt'],
            'endsAt' => ['2026-11-05 18:00', 'endsAt is not an RFC 3339 date-time with an offset, such as 2026-11-05T18:00:00+01:00.', '/data/attributes/endsAt'],
            'day' => ['2026-02-30', 'day is not a date like 2026-10-01.', '/data/attributes/day'],
            'dueOn' => ['tomorrow', 'dueOn is not a date like 2026-12-24.', '/data/attributes/dueOn'],
            'opensAt' => ['09:00:00', 'opensAt is not a time like 09:42:00Z.', '/data/attributes/opensAt'],
            'closesAt' => ['5pm', 'closesAt is not a time like 17:30:00+01:00.', '/data/attributes/closesAt'],
            'breakAt' => ['12:30', 'breakAt is not a time like 09:08:07.', '/data/attributes/breakAt'],
            'window' => [['from' => '2026-10-01'], 'window/from is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.', '/data/attributes/window/from'],
            'until' => ['2026-02-30', 'until is not a date like 2026-10-01.', '/data/attributes/until'],
            'marks' => [['first' => 'soon'], 'marks/first is not a date like 2026-10-01.', '/data/attributes/marks/first'],
        ];
    }

    #[Test]
    #[Group('crud:create')]
    #[Group('spec:errors')]
    public function aBadDateOnCreateIsOneErrorWithItsKindsMessage(): void
    {
        foreach (self::badAttributes() as $name => [$value, $detail, $pointer]) {
            $response = $this->handle('/bookings', 'POST', ['data' => ['type' => 'bookings', 'attributes' => [$name => $value]]]);

            $this->assertOneError($response, 422, 'ATTRIBUTE_VALUE_INVALID', $detail, ['pointer' => $pointer], $name);
        }
    }

    #[Test]
    #[Group('crud:update')]
    #[Group('spec:errors')]
    public function aBadDateOnUpdateIsOneErrorWithItsKindsMessage(): void
    {
        foreach (self::badAttributes() as $name => [$value, $detail, $pointer]) {
            $response = $this->handle('/bookings/1', 'PATCH', ['data' => ['type' => 'bookings', 'id' => '1', 'attributes' => [$name => $value]]]);

            $this->assertOneError($response, 422, 'ATTRIBUTE_VALUE_INVALID', $detail, ['pointer' => $pointer], $name);
        }
    }

    #[Test]
    #[Group('crud:create')]
    public function goodDatesAreStillWritten(): void
    {
        $response = $this->handle('/bookings', 'POST', ['data' => ['type' => 'bookings', 'attributes' => [
            'startsAt' => '2026-10-01T09:00:00Z',
            'day' => '2026-10-01',
            'breakAt' => '12:30:00',
        ]]]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aBadDateRangeBoundIsOneErrorNamingItsParameter(): void
    {
        $cases = [
            'filter[startsRange][min]=banana' => ['startsRange', 'filter[startsRange][min] is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.'],
            'filter[startsRange][max]=2026-10-01' => ['startsRange', 'filter[startsRange][max] is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.'],
            'filter[startsRange][min]=2026-02-30T00:00:00Z' => ['startsRange', 'filter[startsRange][min] is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.'],
            'filter[dayRange][min]=2026-10-01T00:00:00Z' => ['dayRange', 'filter[dayRange][min] is not a date like 2026-10-01.'],
            'filter[dayRange][max]=2026-02-30' => ['dayRange', 'filter[dayRange][max] is not a date like 2026-10-01.'],
            'filter[endsRange][min]=soon' => ['endsRange', 'filter[endsRange][min] is not an RFC 3339 date-time with an offset, such as 2026-01-01T00:00:00Z.'],
            'filter[endsRange][max]=later' => ['endsRange', 'filter[endsRange][max] is not an RFC 3339 date-time with an offset, such as 2026-12-31T23:59:59Z.'],
        ];

        foreach ($cases as $query => [$key, $detail]) {
            $response = $this->handle('/bookings?' . $query);

            $this->assertOneError($response, 400, 'FILTER_VALUE_INVALID', $detail, ['parameter' => 'filter[' . $key . ']'], $query);
        }
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function eachBadBoundIsItsOwnError(): void
    {
        $response = $this->handle('/bookings?filter[dayRange][min]=banana&filter[dayRange][max]=2026-02-30');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertSame(
            ['filter[dayRange][min] is not a date like 2026-10-01.', 'filter[dayRange][max] is not a date like 2026-10-01.'],
            \array_map(static fn(mixed $error): mixed => \is_array($error) ? $error['detail'] ?? null : null, $errors),
        );
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function goodBoundsStillFilter(): void
    {
        $response = $this->handle('/bookings?filter[startsRange][min]=2026-09-30T00:00:00Z&filter[dayRange][max]=2026-10-01');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * @param array<string, string> $source
     */
    private function assertOneError(\Symfony\Component\HttpFoundation\Response $response, int $status, string $code, string $detail, array $source, string $case): void
    {
        self::assertSame($status, $response->getStatusCode(), $case . ': ' . $response->getContent());
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors, $case);
        self::assertCount(1, $errors, $case . ': ' . $response->getContent());
        $error = $errors[0] ?? null;
        self::assertIsArray($error, $case);
        self::assertSame($code, $error['code'] ?? null, $case);
        self::assertSame($detail, $error['detail'] ?? null, $case);
        self::assertSame($source, $error['source'] ?? null, $case);
    }
}
