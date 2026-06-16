<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\RelaxedQueryParametersTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The relax toggle for strict query-parameter validation (bundle ADR 0055): with
 * `json_api.strict_query_parameters` set to `false`, an unrecognized top-level
 * query-parameter family is silently ignored (the pre-strict behaviour) instead
 * of rejected with a `400`. The default-on path is the dual-provider
 * {@see StrictQueryParametersConformanceTestCase}; this single in-memory witness
 * proves the toggle flips the behaviour off.
 */
final class RelaxedQueryParametersTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return RelaxedQueryParametersTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aWellNamedUnsupportedCustomParameterIsIgnoredWhenStrictModeIsOff(): void
    {
        // `myParam` carries an uppercase letter, so it clears the spec baseline
        // (the always-on all-a-z custom-param naming check core runs before dispatch);
        // the strict superset is what would reject it. With strict mode off the strict
        // superset stands down, so the request succeeds and the unknown family is
        // silently dropped — exactly as before strict validation existed.
        $response = $this->handle('/articles?myParam=1');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $document = $this->decode($response);
        self::assertIsArray($document['data'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching')]
    #[Group('spec:profiles')]
    public function theRelatedQueryFamilyIsIgnoredWhenStrictModeIsOffAndTheProfileIsNotNegotiated(): void
    {
        // `relatedQuery` is a profile keyword (a well-named family, so it clears the
        // spec baseline) recognized only when the profile is negotiated. Without
        // negotiation and with strict mode off, it is silently dropped rather than
        // rejected — the pre-strict behaviour the toggle restores.
        $response = $this->handle('/articles/1?include=editors&relatedQuery[editors][sort]=-name');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }
}
