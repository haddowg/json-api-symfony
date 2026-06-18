<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * Strict query-parameter validation end to end over the example app (bundle ADR
 * 0055, core ADR 0059; backs the README "Strict query parameters" section and
 * `docs/configuration.md#strict_query_parameters`). With
 * `json_api.strict_query_parameters` on — its default, and the shipped example's
 * `config/packages/json_api.yaml` omits the key, so strict is on there already — an
 * unrecognized top-level query-parameter family is rejected with a `400`
 * (`QUERY_PARAM_UNRECOGNIZED`, `source.parameter` = the offending base name) rather
 * than silently dropped to a wrong-but-200 result.
 *
 * The relax toggle (`strict_query_parameters: false`) is witnessed by
 * {@see RelaxedQueryParametersTest} over its own example-kernel variant.
 *
 * Two layers reject an unrecognized family, both before dispatch:
 *  - core's always-on spec baseline (the all-a-z custom-param naming rule the spec
 *    mandates a `400` for) owns an all-lowercase family — `?bogus=1` and the
 *    misspelled `?pag[number]=2` (base `pag`); these `400` even with strict off;
 *  - the strict superset this slice adds owns a *well-named* unsupported custom
 *    param (one carrying a non-a-z char such as `?myParam=1`), which the baseline
 *    lets through; relaxing the toggle flips only this case to a silent ignore.
 *
 * A recognized family is never falsely rejected: the reserved JSON:API families
 * (`include`/`fields`/`filter`/`sort`/`page`), the always-on `?withCount`, and the
 * Relationship Queries profile's `relatedQuery`/`rQ` family **when negotiated** all
 * stay `200`.
 */
#[Group('spec:fetching')]
final class StrictQueryParametersTest extends MusicCatalogKernelTestCase
{
    private const string PROFILE_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

    private const string COUNTS_ACCEPT = 'application/vnd.api+json;profile="' . CountableProfile::URI . '"';

    #[Test]
    #[Group('spec:errors')]
    public function anUnknownTopLevelParameterIsRejectedWith400(): void
    {
        // `bogus` is not a reserved family, a declared key, withCount, or a negotiated
        // profile keyword. Strict-on is the default, so the example app rejects it with
        // a 400 keyed on the offending base name — the wrong-but-200 a client typo used
        // to yield is now an explicit error.
        $response = $this->handle('/albums?bogus=1');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $error = $this->firstError($response);
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('QUERY_PARAM_UNRECOGNIZED', $error['code'] ?? null);
        self::assertSame(['parameter' => 'bogus'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:errors')]
    public function aMisspelledReservedFamilyIsRejectedOnItsBaseName(): void
    {
        // `pag[number]=2` is a typo for `page[number]`: PHP parses the bracketed key to
        // the base name `pag`, which is not a reserved family — so the rejection fires
        // at the family level on `pag`, never reaching the page paginator's inner-key
        // validation. The point is the contract: a misspelled family is a clean 400,
        // not a silently-ignored wrong-but-200 first page.
        $response = $this->handle('/albums?pag[number]=2');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());

        $error = $this->firstError($response);
        self::assertSame('QUERY_PARAM_UNRECOGNIZED', $error['code'] ?? null);
        self::assertSame(['parameter' => 'pag'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:errors')]
    public function aWellNamedUnsupportedCustomParameterIsRejected(): void
    {
        // `myParam` follows the custom-param naming rules (an uppercase letter), so it
        // clears core's always-on baseline; the strict superset is what rejects it. The
        // relax toggle flips exactly this case to a silent ignore (RelaxedQueryParametersTest).
        $response = $this->handle('/albums?myParam=1');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());

        $error = $this->firstError($response);
        self::assertSame('QUERY_PARAM_UNRECOGNIZED', $error['code'] ?? null);
        self::assertSame(['parameter' => 'myParam'], $error['source'] ?? null);
    }

    #[Test]
    public function theRelatedQueryFamilyIsRejectedWhenTheProfileIsNotNegotiated(): void
    {
        // `relatedQuery` is the Relationship Queries profile keyword: recognized only
        // when the client negotiates the profile. Without negotiation it is an
        // unrecognized well-named family, so strict mode rejects it on its base name.
        $response = $this->handle('/albums/1?include=tracks&relatedQuery[tracks][sort]=-duration');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());

        $error = $this->firstError($response);
        self::assertSame('QUERY_PARAM_UNRECOGNIZED', $error['code'] ?? null);
        self::assertSame(['parameter' => 'relatedQuery'], $error['source'] ?? null);
    }

    #[Test]
    public function theReservedJsonApiFamiliesAreRecognized(): void
    {
        // Every reserved family used well-formed against the album's declared
        // vocabulary passes the strict check (no 400) and returns a document:
        // `include=tracks`, `fields[albums]=title`, the `WhereHas('tracks')` filter
        // key `filter[tracks]`, the sortable `sort=title`, and `page[number]`.
        $response = $this->handle(
            '/albums?include=tracks&fields[albums]=title&filter[tracks]=1&sort=title&page[number]=1',
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:profiles')]
    public function theWithCountParameterIsRecognizedOnlyWhenTheCountsProfileIsNegotiated(): void
    {
        // `?withCount` is the Relationship Counts profile keyword: a `400` unless the
        // profile is negotiated, recognized when it is.
        $rejected = $this->handle('/albums/1?withCount=tracks');
        self::assertSame(400, $rejected->getStatusCode(), (string) $rejected->getContent());

        $accepted = $this->handle('/albums/1?withCount=tracks', extraServer: ['HTTP_ACCEPT' => self::COUNTS_ACCEPT]);
        self::assertSame(200, $accepted->getStatusCode(), (string) $accepted->getContent());
    }

    #[Test]
    #[Group('spec:profiles')]
    public function aNegotiatedRelatedQueryParameterIsRecognized(): void
    {
        // With the Relationship Queries profile negotiated via the Accept `profile`
        // media-type parameter, the `relatedQuery` family is recognized and the request
        // succeeds — the negotiated counterpart to the un-negotiated 400 above.
        $response = $this->handle(
            '/albums/1?include=tracks&relatedQuery[tracks][sort]=-duration',
            extraServer: ['HTTP_ACCEPT' => self::PROFILE_ACCEPT],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * @return array<string, mixed>
     */
    private function firstError(Response $response): array
    {
        $document = $this->decode($response);

        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $first = $errors[0] ?? null;
        self::assertIsArray($first);

        /** @var array<string, mixed> $first */
        return $first;
    }
}
