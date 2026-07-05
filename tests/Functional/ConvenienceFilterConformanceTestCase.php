<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The convenience filter library acceptance suite (G8b, core ADRs 0075-0077,
 * bundle ADR 0082): the intent-named string strategies
 * ({@see \haddowg\JsonApi\Resource\Filter\Contains} / `StartsWith` / `EndsWith`)
 * and the structured numeric {@see \haddowg\JsonApi\Resource\Filter\Range},
 * declared on the {@see \haddowg\JsonApiBundle\Tests\Functional\App\Resource\ConstrainedFilterArticleResource}
 * and asserted end-to-end over HTTP.
 *
 * Abstract over the kernel so the **same assertions** run against the in-memory
 * provider ({@see InMemoryConvenienceFilterTest}) and the Doctrine provider
 * ({@see DoctrineConvenienceFilterTest}) — the two new `starts`/`ends` operators
 * and the two push-down `Range` predicates must select identically on both. The
 * canonical fixtures seed `articles` ids `1`-`5` with the titles "JSON:API in PHP",
 * "Second article", "Building bundles", "Zebra patterns", "Async pipelines".
 */
abstract class ConvenienceFilterConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aContainsFilterMatchesASubstringCaseInsensitively(): void
    {
        // Contains is the `like` operator: "json" matches only "JSON:API in PHP" (id 1),
        // case-insensitively. Identical on both providers (in-memory stripos, Doctrine
        // LOWER(...) LIKE '%json%').
        self::assertSame(['1'], $this->ids($this->fetchDocument('/articles?filter[titleHas]=json')));
        self::assertSame(['1'], $this->ids($this->fetchDocument('/articles?filter[titleHas]=JSON')));
        self::assertSame([], $this->ids($this->fetchDocument('/articles?filter[titleHas]=zzz')));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aStartsWithFilterMatchesAPrefixCaseInsensitively(): void
    {
        // StartsWith is the NEW `starts` operator (in-memory stripos===0, Doctrine
        // LIKE 'async%'): "Async pipelines" (id 5) is the only title starting "async".
        self::assertSame(['5'], $this->ids($this->fetchDocument('/articles?filter[titleStarts]=async')));
        self::assertSame(['5'], $this->ids($this->fetchDocument('/articles?filter[titleStarts]=ASYNC')));
        // "json" is a substring but not a prefix of any title — no match (distinguishes
        // starts from contains).
        self::assertSame([], $this->ids($this->fetchDocument('/articles?filter[titleStarts]=in PHP')));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function anEndsWithFilterMatchesASuffixCaseInsensitively(): void
    {
        // EndsWith is the NEW `ends` operator (in-memory str_ends_with, Doctrine
        // LIKE '%php'): "JSON:API in PHP" (id 1) is the only title ending "php".
        self::assertSame(['1'], $this->ids($this->fetchDocument('/articles?filter[titleEnds]=php')));
        self::assertSame(['1'], $this->ids($this->fetchDocument('/articles?filter[titleEnds]=PHP')));
        // "json" is a prefix, not a suffix — no match (distinguishes ends from starts).
        self::assertSame([], $this->ids($this->fetchDocument('/articles?filter[titleEnds]=json')));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aRangeFilterAppliesAnInclusiveNumericMinAndMax(): void
    {
        // Range over the int `id` column: min/max in one key, numeric coercion.
        // min=3 keeps ids 3,4,5; min=2&max=4 keeps 2,3,4. Sorted by title for a stable
        // assertion order across providers.
        self::assertSame(['3', '4', '5'], $this->sortedIds('/articles?filter[idRange][min]=3'));
        self::assertSame(['2', '3', '4'], $this->sortedIds('/articles?filter[idRange][min]=2&filter[idRange][max]=4'));
        // max alone is a <=: ids 1,2.
        self::assertSame(['1', '2'], $this->sortedIds('/articles?filter[idRange][max]=2'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aRangeFilterWithAnOpenBlankBoundIsNotA400(): void
    {
        // A blank bound is open-ended (treated as absent), so filter[idRange][max]=
        // does NOT 400 and leaves the min as the only predicate — identical on both
        // providers (the in-memory and Doctrine `bound()` both treat '' as absent).
        self::assertSame(['4', '5'], $this->sortedIds('/articles?filter[idRange][min]=4&filter[idRange][max]='));
        // Both bounds blank is a no-op: every article.
        self::assertSame(['1', '2', '3', '4', '5'], $this->sortedIds('/articles?filter[idRange][min]=&filter[idRange][max]='));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aRangeFilterRejectsAMalformedNumericBound(): void
    {
        // A malformed present bound is a clean 400 (the preset numeric() constraint,
        // validated pre-provider) on BOTH providers — the bad value never reaches the
        // data layer.
        $response = $this->handle('/articles?filter[idRange][min]=banana');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $error = $this->firstError($this->decode($response));
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('FILTER_VALUE_INVALID', $error['code'] ?? null);
        self::assertSame(['parameter' => 'filter[idRange]'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aDateRangeFilterWithACalendarValidBoundSelectsIdentically(): void
    {
        // A calendar-valid ISO-8601 bound coerces to \DateTimeImmutable and compares
        // temporally on BOTH providers. The `articles` fixtures leave `publishedAt`
        // null, so a `min` bound excludes every row identically (in-memory
        // `null >= $min` is false; Doctrine excludes NULL on `>=`) — the parity that
        // matters here is "same result on both", not the specific membership. The
        // point is the valid coercion path never 500s or diverges.
        self::assertSame([], $this->sortedIds('/articles?filter[publishedRange][min]=2000-01-01'));
        self::assertSame([], $this->sortedIds('/articles?filter[publishedRange][min]=1997-05-21T12:30:00Z'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aDateRangeFilterRejectsACalendarInvalidBoundIdenticallyOnBothProviders(): void
    {
        // The headline DateRange parity case: `1997-13-99` (month 13, day 99) passes
        // the deliberately-lenient shape Pattern but is not a real date, so it does
        // not coerce to \DateTimeImmutable. Without the temporal-validity check it
        // would reach the data layer as a raw string and select DIVERGENTLY — the
        // in-memory handler comparing a \DateTimeImmutable column lexically (all rows)
        // vs Doctrine binding a non-date string (no rows on SQLite, a driver 500 on a
        // strict driver). Instead it is a clean, identical 400 on both providers,
        // before the provider runs.
        $response = $this->handle('/articles?filter[publishedRange][min]=1997-13-99');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $error = $this->firstError($this->decode($response));
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('FILTER_VALUE_INVALID', $error['code'] ?? null);
        self::assertSame(['parameter' => 'filter[publishedRange]'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aDateRangeFilterRejectsAShapeInvalidBound(): void
    {
        // A bound that fails the shape Pattern itself (`banana`) is rejected by the
        // translated Pattern constraint — a clean 400 on both providers, the same as
        // a malformed numeric Range bound.
        $response = $this->handle('/articles?filter[publishedRange][max]=banana');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());

        $error = $this->firstError($this->decode($response));
        self::assertSame('FILTER_VALUE_INVALID', $error['code'] ?? null);
        self::assertSame(['parameter' => 'filter[publishedRange]'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function anOrderedRangeBoundNeverMatchesANullColumnOnEitherProvider(): void
    {
        // Core ADR 0116: an ordered comparison — and a Range bound is a `>=`/`<=` pair —
        // against a column whose value is `null` never matches; the row is EXCLUDED,
        // mirroring SQL three-valued logic (a NULL column against a present bound is
        // UNKNOWN) rather than PHP's silent coercion of `null` toward `0`. The `articles`
        // fixtures leave `publishedAt` null on every row, so a present DateRange bound must
        // exclude them ALL — on the in-memory witness AND the Doctrine provider.
        //
        // This is exactly the divergence the ADR closes: before the fix the in-memory
        // `range()` coerced `null <= max` to true and returned every row, diverging from
        // the Doctrine provider (whose SQL `publishedAt <= :max` excludes NULL). The fix
        // reads the raw column before the deserializer and drops a null, so both providers
        // now converge on the empty set.

        // Control: the SAME max-bound mechanism DOES keep non-null in-bound rows — an int
        // `id` column (never null) returns every article under a high max — so an empty
        // publishedRange result below reads as "null excluded", not "empty for lack of data".
        self::assertSame(['1', '2', '3', '4', '5'], $this->sortedIds('/articles?filter[idRange][max]=100'));

        // A max-only bound: pre-fix the in-memory witness returned all five (null coerced
        // to 0 <= max); the fix excludes every null row on both providers.
        self::assertSame([], $this->sortedIds('/articles?filter[publishedRange][max]=2999-12-31'));
        // A min-only bound is UNKNOWN against null too (null >= min never holds).
        self::assertSame([], $this->sortedIds('/articles?filter[publishedRange][min]=1900-01-01'));
        // And a two-sided bound spanning any plausible instant still excludes every null row.
        self::assertSame([], $this->sortedIds('/articles?filter[publishedRange][min]=1900-01-01&filter[publishedRange][max]=2999-12-31'));
    }

    /**
     * The numerically-sorted ids of `$path`'s primary `articles` data — a stable
     * order for set-membership assertions that does not depend on the provider's
     * default ordering (sorted client-side, so it needs no declared sort key).
     *
     * @return list<string>
     */
    private function sortedIds(string $path): array
    {
        $ids = $this->ids($this->fetchDocument($path));
        \sort($ids, \SORT_NUMERIC);

        return $ids;
    }

    /**
     * Fetches `$path` and returns the decoded document, asserting a 200 JSON:API
     * response.
     *
     * @return array<string, mixed>
     */
    private function fetchDocument(string $path): array
    {
        $response = $this->handle($path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->decode($response);
    }

    /**
     * The ids of the document's primary `articles` data, in document order.
     *
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function ids(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            self::assertSame('articles', $resource['type'] ?? null);

            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * The document's first error object.
     *
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
