<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Http;

use haddowg\JsonApiBundle\Http\DeprecationHeaders;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Characterizes the {@see DeprecationHeaders} value object (bundle ADR 0054): the
 * bool-vs-date `Deprecation` (the IETF Deprecation-header draft), the `Sunset` +
 * companion `Link` (RFC 8594), the `mergeOver()` fallback, and the
 * no-clobber-of-an-explicit-header behaviour.
 */
final class DeprecationHeadersTest extends TestCase
{
    #[Test]
    public function aBareTrueDeprecationEmitsTheStringTrue(): void
    {
        $response = new Response();
        (new DeprecationHeaders(deprecation: true))->applyTo($response);

        self::assertSame('true', $response->headers->get('Deprecation'));
    }

    #[Test]
    public function aDateDeprecationEmitsTheDateVerbatim(): void
    {
        $response = new Response();
        (new DeprecationHeaders(deprecation: 'Sun, 11 Nov 2024 23:59:59 GMT'))->applyTo($response);

        self::assertSame('Sun, 11 Nov 2024 23:59:59 GMT', $response->headers->get('Deprecation'));
    }

    #[Test]
    public function sunsetEmitsTheDateAndTheCompanionLink(): void
    {
        $response = new Response();
        (new DeprecationHeaders(
            sunset: 'Wed, 11 Nov 2026 23:59:59 GMT',
            sunsetLink: 'https://example.test/sunset',
        ))->applyTo($response);

        self::assertSame('Wed, 11 Nov 2026 23:59:59 GMT', $response->headers->get('Sunset'));
        self::assertSame('<https://example.test/sunset>; rel="sunset"', $response->headers->get('Link'));
    }

    #[Test]
    public function aSunsetLinkWithoutASunsetEmitsNeither(): void
    {
        $response = new Response();
        (new DeprecationHeaders(sunsetLink: 'https://example.test/sunset'))->applyTo($response);

        self::assertFalse($response->headers->has('Sunset'));
        self::assertFalse($response->headers->has('Link'));
    }

    #[Test]
    public function anExplicitAppHeaderIsNotClobbered(): void
    {
        $response = new Response();
        $response->headers->set('Deprecation', 'app-set');
        $response->headers->set('Sunset', 'app-set-sunset');

        (new DeprecationHeaders(deprecation: true, sunset: 'Wed, 11 Nov 2026 23:59:59 GMT'))->applyTo($response);

        self::assertSame('app-set', $response->headers->get('Deprecation'));
        self::assertSame('app-set-sunset', $response->headers->get('Sunset'));
    }

    #[Test]
    public function mergeOverInheritsUnsetValuesFromTheDefault(): void
    {
        $default = new DeprecationHeaders(deprecation: true, sunset: 'a', sunsetLink: 'b');
        $merged = (new DeprecationHeaders(deprecation: 'override'))->mergeOver($default);

        self::assertSame('override', $merged->deprecation);
        self::assertSame('a', $merged->sunset);
        self::assertSame('b', $merged->sunsetLink);
    }

    #[Test]
    public function isEmptyWhenNeitherDeprecationNorSunset(): void
    {
        self::assertTrue((new DeprecationHeaders())->isEmpty());
        self::assertTrue((new DeprecationHeaders(deprecation: false))->isEmpty());
        self::assertFalse((new DeprecationHeaders(deprecation: true))->isEmpty());
        self::assertFalse((new DeprecationHeaders(sunset: 'x'))->isEmpty());
    }
}
