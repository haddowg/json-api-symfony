<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A bad date reads as core's one message for its kind, whether it is an attribute write,
 * a date range bound or a comparison or list filter's value, with the example the
 * declared one or else the fixed one. Its source pointer or parameter says which value it
 * was. The kernels run in French with one key translated. The same assertions run on the
 * in-memory and Doctrine kernels.
 */
abstract class DateMessageConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string DATE_TIME = 'This value is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.';

    /**
     * @return array<string, array{mixed, string, string}>
     */
    private static function badAttributes(): array
    {
        return [
            'startsAt' => ['last year', self::DATE_TIME, '/data/attributes/startsAt'],
            'endsAt' => ['2026-11-05 18:00', 'This value is not an RFC 3339 date-time with an offset, such as 2026-11-05T18:00:00+01:00.', '/data/attributes/endsAt'],
            'day' => ['2026-02-30', 'This value is not a date like 2026-10-01.', '/data/attributes/day'],
            'dueOn' => ['tomorrow', 'This value is not a date like 2026-12-24.', '/data/attributes/dueOn'],
            'opensAt' => ['09:00:00', 'This value is not a time like 09:42:00Z.', '/data/attributes/opensAt'],
            'closesAt' => ['5pm', 'This value is not a time like 17:30:00+01:00.', '/data/attributes/closesAt'],
            'breakAt' => ['12:30', 'This value is not a time like 14:30:00.', '/data/attributes/breakAt'],
            'window' => [['from' => '2026-10-01'], self::DATE_TIME, '/data/attributes/window/from'],
            'until' => ['2026-02-30', 'This value is not a date like 2026-10-01.', '/data/attributes/until'],
            'marks' => [['first' => 'soon'], 'This value is not a date like 2026-10-01.', '/data/attributes/marks/first'],
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

    /**
     * Each bad filter value or bound, its parameter key and its detail. The kernel runs in
     * French with only `FILTER_VALUE_INVALID.date.detail` translated, so a date reads in
     * French and every other kind keeps its default.
     *
     * @return array<string, array{string, string}>
     */
    private static function badFilters(): array
    {
        return [
            'filter[startsRange][min]=banana' => ['startsRange', self::DATE_TIME],
            'filter[startsRange][max]=2026-10-01' => ['startsRange', self::DATE_TIME],
            'filter[startsRange][min]=2026-02-30T00:00:00Z' => ['startsRange', self::DATE_TIME],
            'filter[dayRange][min]=2026-10-01T00:00:00Z' => ['dayRange', "Cette valeur n'est pas une date comme 2026-10-01."],
            'filter[endsRange][min]=soon' => ['endsRange', 'This value is not an RFC 3339 date-time with an offset, such as 2026-01-01T00:00:00Z.'],
            'filter[endsRange][max]=later' => ['endsRange', 'This value is not an RFC 3339 date-time with an offset, such as 2026-12-31T23:59:59Z.'],
            'filter[startsAt]=banana' => ['startsAt', self::DATE_TIME],
            'filter[startsAfter]=2026-10-01' => ['startsAfter', self::DATE_TIME],
            'filter[days]=2026-02-30' => ['days', "Cette valeur n'est pas une date comme 2026-10-01."],
            'filter[notDueOn]=tomorrow' => ['notDueOn', "Cette valeur n'est pas une date comme 2026-12-24."],
            'filter[opensAt]=09:00:00' => ['opensAt', 'This value is not a time like 09:42:00Z.'],
            'filter[breakAt]=12:30' => ['breakAt', 'This value is not a time like 14:30:00.'],
        ];
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aBadDateFilterValueIsOneErrorWithItsKindsMessage(): void
    {
        foreach (self::badFilters() as $query => [$key, $detail]) {
            $response = $this->handle('/bookings?' . $query);

            $this->assertOneError($response, 400, 'FILTER_VALUE_INVALID', $detail, ['parameter' => 'filter[' . $key . ']'], $query);
        }
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function eachBadBoundOrMemberIsItsOwnError(): void
    {
        foreach (['filter[dayRange][min]=banana&filter[dayRange][max]=2026-02-30', 'filter[days]=banana,2026-10-01,2026-02-30'] as $query) {
            $response = $this->handle('/bookings?' . $query);

            self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
            $errors = $this->decode($response)['errors'] ?? null;
            self::assertIsArray($errors);
            self::assertSame(
                ["Cette valeur n'est pas une date comme 2026-10-01.", "Cette valeur n'est pas une date comme 2026-10-01."],
                \array_map(static fn(mixed $error): mixed => \is_array($error) ? $error['detail'] ?? null : null, $errors),
                $query,
            );
        }
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function goodDateFilterValuesStillFilter(): void
    {
        foreach ([
            'filter[startsRange][min]=2026-09-30T00:00:00Z&filter[dayRange][max]=2026-10-01',
            'filter[startsAt]=2026-10-01T09:00:00Z',
            'filter[startsAfter]=2026-09-30T00:00:00Z',
            'filter[days]=2026-10-01,2026-10-02',
            'filter[breakAt]=12:30:00',
        ] as $query) {
            $response = $this->handle('/bookings?' . $query);

            self::assertSame(200, $response->getStatusCode(), $query . ': ' . $response->getContent());
        }
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
