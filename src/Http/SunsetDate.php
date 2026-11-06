<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Http;

/**
 * Reads the date out of a declared `sunset` value for the OpenAPI document. The header
 * itself still carries the declared string verbatim.
 *
 * It accepts the three HTTP-date forms RFC 9110 obliges a recipient to read (IMF-fixdate,
 * RFC 850 and asctime), a structured-field date such as `@1924991999`, and an ISO 8601
 * date or date-time. Anything else, a relative phrase like `+1 year` included, reads as no
 * date, so the document leaves out the sunset sentence rather than state a date the author
 * never wrote.
 *
 * @internal
 */
final class SunsetDate
{
    /**
     * The HTTP-date forms after their leading day name, which is dropped before parsing
     * so a wrong one cannot move the date to another day.
     */
    private const array HTTP_DATE_FORMATS = [
        'd M Y H:i:s \G\M\T',
        'd-M-y H:i:s \G\M\T',
        'M j H:i:s Y',
    ];

    public static function parse(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        // asctime pads a single-digit day with a space ("Nov  6").
        $value = (string) \preg_replace('/\s+/', ' ', \trim($value));
        if ($value === '') {
            return null;
        }

        $utc = new \DateTimeZone('UTC');

        $withoutDayName = (string) \preg_replace('/^[A-Za-z]+,? /', '', $value);
        foreach (self::HTTP_DATE_FORMATS as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $withoutDayName, $utc);
            if ($date !== false && self::isClean()) {
                return $date;
            }
        }

        if (\preg_match('/^@(-?\d+)$/', $value, $match) === 1) {
            return (new \DateTimeImmutable('@' . $match[1]))->setTimezone($utc);
        }

        if (\preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/i', $value, $match) === 1) {
            // The constructor would roll an impossible day over, 2050-02-30 to 2 March.
            if (!\checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
                return null;
            }

            try {
                return new \DateTimeImmutable($value, $utc);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * Whether the last createFromFormat() parsed without warnings, which it raises
     * rather than fails on an impossible date such as 31 February.
     */
    private static function isClean(): bool
    {
        $errors = \DateTimeImmutable::getLastErrors();

        return $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
    }
}
