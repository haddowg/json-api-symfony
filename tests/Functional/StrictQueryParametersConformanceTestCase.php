<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Tier-0 acceptance suite for strict query-parameter validation (bundle ADR
 * 0055, core ADR 0059), run identically against the in-memory provider
 * ({@see InMemoryStrictQueryParametersTest}) and the Doctrine provider
 * ({@see DoctrineStrictQueryParametersTest}).
 *
 * With `json_api.strict_query_parameters` on (the default — both kernels), an
 * unrecognized top-level query-parameter family is rejected with a `400`
 * {@see \haddowg\JsonApi\Exception\QueryParamUnrecognized} keyed on the offending
 * base name, rather than silently ignored. A param is recognized when its base
 * name is:
 *  - a reserved JSON:API family used well-formed (`include`, `fields`, `filter`,
 *    `sort`, `page`) — their internal key validation still owns an unknown
 *    filter/sort key or a bad page;
 *  - the always-on implementation-specific `withCount`;
 *  - a negotiated profile's keyword (the Relationship Queries profile's
 *    `relatedQuery`/`rQ`, asserted by {@see RelationshipQueriesConformanceTestCase}).
 *
 * A misspelled reserved family (`pag[number]` for `page`) fails on the base name
 * `pag`, not on the inner key — proving the check is family-level. An undeclared
 * key *inside* a recognized family (`filter[nope]`) is untouched by this slice; it
 * still flows to the existing per-family key validation (`400` FILTERING_UNRECOGNIZED),
 * asserted by {@see ReadQueryConformanceTestCase}.
 *
 * Two layers reject an unrecognized family, both before dispatch: the always-on
 * spec baseline (core's all-a-z custom-param naming check, which owns the `bogus`
 * and `pag` cases) and the strict superset added by this slice (which owns a
 * *well-named* unsupported custom param such as `myParam`, that the baseline lets
 * through). The relax toggle (`strict_query_parameters: false`) stands down only
 * the superset — the baseline stays, so it is the well-named case that flips to a
 * silent ignore ({@see RelaxedQueryParametersTest}).
 *
 * The shared {@see App\Resource\BaseArticleResource} declares the filters, sorts,
 * fields and relations the recognized-family assertions address.
 */
abstract class StrictQueryParametersConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    #[Group('spec:fetching')]
    #[Group('spec:errors')]
    public function anUnknownTopLevelParameterIsRejectedWith400(): void
    {
        // `bogus` is not a reserved family, a declared key, withCount, or a negotiated
        // profile keyword. It is all-a-z, so the spec baseline (the always-on
        // custom-param naming check) is what owns this 400 — the user-facing contract
        // a strict server presents for a plain typo. The strict superset adds the
        // well-named case (see aWellNamedUnsupportedCustomParameterIsRejected).
        $response = $this->handle('/articles?bogus=1');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $error = $this->firstError($this->decode($response));
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('QUERY_PARAM_UNRECOGNIZED', $error['code'] ?? null);
        self::assertSame(['parameter' => 'bogus'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching')]
    #[Group('spec:errors')]
    public function aMisspelledReservedFamilyIsRejectedOnItsBaseName(): void
    {
        // `pag[number]=2` is a typo for `page[number]`: PHP parses the bracketed key to
        // the base name `pag`, which is not a reserved family — so the rejection fires
        // at the family level on `pag`, never reaching the page paginator's inner-key
        // validation. `pag` is all-a-z, so (as for `bogus`) the spec baseline owns this
        // 400; the point is the user-facing contract — a misspelled family is a 400,
        // not a silently-ignored wrong-but-200 result.
        $response = $this->handle('/articles?pag[number]=2');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());

        $error = $this->firstError($this->decode($response));
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('QUERY_PARAM_UNRECOGNIZED', $error['code'] ?? null);
        self::assertSame(['parameter' => 'pag'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching')]
    #[Group('spec:errors')]
    public function aWellNamedUnsupportedCustomParameterIsRejected(): void
    {
        // `myParam` follows the custom-param naming rules (an uppercase letter), but
        // the server does not recognize it; strict mode 400s it (the spec permits a
        // 400 or an ignore for such a param — strict chooses 400).
        $response = $this->handle('/articles?myParam=1');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());

        $error = $this->firstError($this->decode($response));
        self::assertSame('QUERY_PARAM_UNRECOGNIZED', $error['code'] ?? null);
        self::assertSame(['parameter' => 'myParam'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function theReservedJsonApiFamiliesAreRecognized(): void
    {
        // Every reserved family used well-formed against the resource's declared
        // vocabulary passes the strict check (no 400) and returns a document.
        $response = $this->handle(
            '/articles?include=author&fields[articles]=title&filter[title]=Zebra%20patterns&sort=title&page[number]=1',
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function theAlwaysOnWithCountParameterIsRecognized(): void
    {
        // `withCount` is recognized automatically (no host registration), so a
        // ?withCount request on a single resource is not rejected.
        $response = $this->handle('/articles/1?withCount=pagedComments');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    // --- helpers ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function firstError(array $document): array
    {
        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $first = $errors[0] ?? null;
        self::assertIsArray($first);

        /** @var array<string, mixed> $first */
        return $first;
    }
}
