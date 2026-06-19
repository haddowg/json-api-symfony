<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The read-query acceptance suite (backs `data-layer.md` / `doctrine.md`):
 * filtering, sorting, sparse fieldsets, compound `?include` and the singular-filter
 * collapse, asserted as spec-compliant documents and executed as real DQL through
 * the bundle's reference Doctrine provider over the seeded catalog.
 *
 * The probes lean on the seeded shape: the `tracks` resource declares an `explicit`
 * filter defaulting to `false`, so a bare `/tracks` list is the three non-explicit
 * tracks; `albums` carries a `WhereHas('tracks')` relationship-existence filter
 * (rendered as an `EXISTS` subquery) and default-includes its `artist`; `artists`
 * declares a singular `slug` filter that collapses zero-to-one.
 */
#[Group('spec:fetching')]
final class ReadQueryTest extends MusicCatalogKernelTestCase
{
    // --- filtering -----------------------------------------------------------

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aLikeFilterMatchesASubstringCaseInsensitively(): void
    {
        // `Where::make('title', 'title', 'like')` is an ASCII case-insensitive
        // contains: "air" matches "Airbag" (track 1) and nothing else.
        self::assertSame(['1'], $this->trackIds($this->fetch('/tracks?filter[title]=air')));
        self::assertSame(['1'], $this->trackIds($this->fetch('/tracks?filter[title]=AIR')));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aBooleanFilterWithADefaultNarrowsTheCollection(): void
    {
        // `explicit` defaults to false, so the bare collection hides the one explicit
        // track (Paranoid Android, track 2); requesting explicit=true surfaces it.
        self::assertSame(['1', '3', '4'], $this->trackIds($this->fetch('/tracks?sort=title')));
        self::assertSame(['2'], $this->trackIds($this->fetch('/tracks?filter[explicit]=true')));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:fetching-relationships')]
    public function aWhereHasFilterRendersAsAnExistsSubquery(): void
    {
        // `WhereHas('tracks')` keeps albums with at least one related track. The
        // request value is ignored — presence is the predicate. Albums 1 and 2 own
        // tracks (album 3 is unpublished and hidden by the extension regardless).
        self::assertSame(['2', '1'], $this->albumIds($this->fetch('/albums?filter[tracks]=1&sort=title')));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:fetching-relationships')]
    public function aWhereThroughFilterTraversesTheArtistRelationByName(): void
    {
        // `WhereThrough('artist.name')` is a dotted-traversal EXISTS-ANY semi-join
        // (bundle ADR 0069): `filter[artist.name]=…` keeps albums whose artist's name
        // matches. The Doctrine reference renders it as a correlated EXISTS subquery
        // rooted on the related `Artist` (never a fetch-join). Album 1 (OK Computer)
        // belongs to Radiohead, album 2 (Dummy) to Portishead; album 3 is unpublished
        // and hidden by the published scope.
        self::assertSame(['1'], $this->albumIds($this->fetch('/albums?filter[artist.name]=Radiohead')));
        self::assertSame(['2'], $this->albumIds($this->fetch('/albums?filter[artist.name]=Portishead')));
        // A name no artist carries excludes every album — an empty primary collection.
        self::assertSame([], $this->albumIds($this->fetch('/albums?filter[artist.name]=Nobody')));
    }

    // --- convenience filter library (G8b) ------------------------------------

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aContainsFilterMatchesASubstringCaseInsensitively(): void
    {
        // `Contains::make('title')` is the intent-named substring search (the `like`
        // operator): "comput" keeps OK Computer (album 1) only, case-insensitively.
        self::assertSame(['1'], $this->albumIds($this->fetch('/albums?filter[title]=comput')));
        self::assertSame(['1'], $this->albumIds($this->fetch('/albums?filter[title]=COMPUT')));
        // A substring no title carries excludes every album.
        self::assertSame([], $this->albumIds($this->fetch('/albums?filter[title]=zzz')));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aRangeFilterAppliesAnInclusiveNumericMinAndMax(): void
    {
        // `Range::make('rating', 'averageRating')`: nested min/max in one key, numeric
        // coercion so the comparison is numeric. OK Computer 9.8, Dummy 9.1.
        self::assertSame(['1'], $this->albumIds($this->fetch('/albums?filter[rating][min]=9.5')));
        self::assertSame(['2'], $this->albumIds($this->fetch('/albums?filter[rating][min]=9&filter[rating][max]=9.5')));
        // Both bounds present, spanning both rated albums (default releasedAt-desc order).
        self::assertSame(['1', '2'], $this->albumIds($this->fetch('/albums?filter[rating][min]=9&filter[rating][max]=10')));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aRangeFilterWithAnOpenBlankBoundIsNotA400(): void
    {
        // A blank bound is open-ended (treated as absent) — `filter[rating][max]=`
        // must NOT 400 and leaves the min as the only predicate.
        self::assertSame(['1'], $this->albumIds($this->fetch('/albums?filter[rating][min]=9.5&filter[rating][max]=')));
        // Both bounds blank is a no-op: every rated album (album 3 is hidden by scope).
        self::assertSame(['1', '2'], $this->albumIds($this->fetch('/albums?filter[rating][min]=&filter[rating][max]=')));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aRangeFilterRejectsAMalformedNumericBound(): void
    {
        // A malformed present bound is a clean 400 (the preset numeric() constraint,
        // validated before the filter reaches the provider).
        $response = $this->handle('/albums?filter[rating][min]=banana');

        self::assertSame(400, $response->getStatusCode());
        $error = $this->firstError($response);
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('FILTER_VALUE_INVALID', $error['code'] ?? null);
        self::assertSame(['parameter' => 'filter[rating]'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aDateRangeFilterAppliesAnInclusiveTemporalMinAndMax(): void
    {
        // `DateRange::make('releasedAt')`: ISO-8601 bounds coerced to \DateTimeImmutable.
        // OK Computer 1997-05-21, Dummy 1994-08-22.
        self::assertSame(['1'], $this->albumIds($this->fetch('/albums?filter[releasedAt][min]=1995-01-01')));
        self::assertSame(['2'], $this->albumIds($this->fetch('/albums?filter[releasedAt][min]=1994-01-01&filter[releasedAt][max]=1995-01-01')));
        // An open upper bound keeps both released albums (default releasedAt-desc order).
        self::assertSame(['1', '2'], $this->albumIds($this->fetch('/albums?filter[releasedAt][min]=1990-01-01')));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aDateRangeFilterRejectsAMalformedIso8601Bound(): void
    {
        // A malformed ISO-8601 bound is a clean 400 (the preset ISO-8601 constraint).
        $response = $this->handle('/albums?filter[releasedAt][min]=not-a-date');

        self::assertSame(400, $response->getStatusCode());
        $error = $this->firstError($response);
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('FILTER_VALUE_INVALID', $error['code'] ?? null);
        self::assertSame(['parameter' => 'filter[releasedAt]'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aDateRangeFilterRejectsACalendarInvalidBound(): void
    {
        // `1997-13-99` (month 13, day 99) passes the deliberately-lenient ISO-8601
        // SHAPE pattern but is not a real date, so it does not coerce to a
        // \DateTimeImmutable. The filter-value validator's temporal-validity check
        // rejects it as a clean 400 BEFORE the data layer — so the bound never reaches
        // the query as a raw string (which would compare divergently across providers).
        $response = $this->handle('/albums?filter[releasedAt][min]=1997-13-99');

        self::assertSame(400, $response->getStatusCode());
        $error = $this->firstError($response);
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('FILTER_VALUE_INVALID', $error['code'] ?? null);
        self::assertSame(['parameter' => 'filter[releasedAt]'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function anUnknownFilterKeyRendersA400ErrorDocument(): void
    {
        $response = $this->handle('/tracks?filter[nope]=x');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $error = $this->firstError($response);
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('FILTERING_UNRECOGNIZED', $error['code'] ?? null);
        self::assertSame(['parameter' => 'filter[nope]'], $error['source'] ?? null);
    }

    // --- singular filters ----------------------------------------------------

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aSingularFilterCollapsesTheCollectionToASingleResource(): void
    {
        // `Where::make('slug')->singular()`: a unique slug match renders the matched
        // resource as the document's primary `data` object, not a one-element array.
        $document = $this->fetch('/artists?filter[slug]=radiohead');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('artists', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aSingularFilterWithNoMatchRendersDataNull(): void
    {
        // Zero matches is `data: null` with a 200 — the collection exists; the
        // singular result is simply empty.
        $document = $this->fetch('/artists?filter[slug]=no-such-artist');

        self::assertArrayHasKey('data', $document);
        self::assertNull($document['data']);
    }

    // --- sorting -------------------------------------------------------------

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function sortingAscendingOrdersTheCollection(): void
    {
        // By title: "Airbag" < "Exit Music…" < "Mysterons" (tracks 1, 3, 4; the
        // explicit track 2 is hidden by the default filter).
        self::assertSame(['1', '3', '4'], $this->trackIds($this->fetch('/tracks?sort=title')));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aMinusPrefixSortsDescending(): void
    {
        // By trackNumber desc: track 3 (no. 3), track 1 (no. 1), track 4 (no. 1) —
        // ties broken by storage order.
        self::assertSame('3', $this->trackIds($this->fetch('/tracks?sort=-trackNumber'))[0] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aDefaultSortAppliesWhenNoSortIsRequested(): void
    {
        // AlbumResource declares defaultSort() = releasedAt descending. With no
        // ?sort the visible albums (1 OK Computer 1997, 2 Dummy 1994; album 3 is
        // hidden by the published scope) order newest-first over the Doctrine
        // provider — an ORDER BY releasedAt DESC, not storage order.
        self::assertSame(['1', '2'], $this->albumIds($this->fetch('/albums')));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function anExplicitSortOverridesTheDefaultEntirely(): void
    {
        // ?sort=title overrides the releasedAt-descending default: ascending title
        // is Dummy (2) before OK Computer (1), the reverse of the default order.
        self::assertSame(['2', '1'], $this->albumIds($this->fetch('/albums?sort=title')));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:errors')]
    public function anUnknownSortFieldRendersA400ErrorDocument(): void
    {
        $response = $this->handle('/tracks?sort=nope');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('SORTING_UNRECOGNIZED', $this->firstError($response)['code'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:errors')]
    public function aDeclaredButUnsortableFieldRendersA400ErrorDocument(): void
    {
        // `genres` is a declared attribute never opted into sorting.
        $response = $this->handle('/tracks?sort=genres');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('SORTING_UNRECOGNIZED', $this->firstError($response)['code'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aSortReadThroughTheBrowserCarriesExactlyTheOrderedIds(): void
    {
        // The dogfood `?sort` witness through the shipped browser: an ascending title
        // sort over `/tracks` carries exactly [Airbag (1), Exit Music (3), Mysterons
        // (4)] IN THAT ORDER (the explicit track 2 is hidden by the default filter) —
        // a single fluent assertion over status, content type and ordered membership.
        $this->browser()
            ->get('/tracks?sort=title')
            ->assertFetchedManyInOrder(['1', '3', '4'], 'tracks');
    }

    // --- sparse fieldsets ----------------------------------------------------

    #[Test]
    #[Group('spec:fetching-sparse-fieldsets')]
    public function sparseFieldsetsNarrowTheAttributes(): void
    {
        $data = $this->fetch('/tracks/1?fields[tracks]=title')['data'] ?? null;
        self::assertIsArray($data);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertArrayHasKey('title', $attributes);
        self::assertArrayNotHasKey('durationSeconds', $attributes);
        self::assertArrayNotHasKey('genres', $attributes);
    }

    #[Test]
    #[Group('spec:fetching-resources')]
    public function aSingleReadIsWholeMemberExactWithNoLeak(): void
    {
        // The dogfood no-leak witness: a single `/tracks/1` read is compared
        // WHOLE-MEMBER against the exact resource object the seed produces (every
        // attribute, the computed `displayTitle`, the per-member meta, both
        // relationships' linkage and links). A leaked or extra member — a hidden
        // column surfacing, a stray relationship — would fail this, far stronger than
        // spot-checking one attribute.
        $this->browser()
            ->get('/tracks/1')
            ->assertFetchedOneExact([
                'type' => 'tracks',
                'id' => '1',
                'meta' => ['served_by' => 'music-catalog'],
                'links' => ['self' => 'https://music.example/tracks/1'],
                'attributes' => [
                    'title' => 'Airbag',
                    'trackNumber' => 1,
                    'durationSeconds' => 284,
                    'explicit' => false,
                    'genres' => ['rock', 'alternative'],
                    'previewOffset' => '00:00:30',
                    'displayTitle' => '1. Airbag',
                ],
                'relationships' => [
                    'album' => [
                        'links' => [
                            'self' => 'https://music.example/tracks/1/relationships/album',
                            'related' => 'https://music.example/tracks/1/album',
                        ],
                        'data' => ['type' => 'albums', 'id' => '1'],
                    ],
                    'playlists' => [
                        'links' => [
                            'self' => 'https://music.example/tracks/1/relationships/playlists',
                            'related' => 'https://music.example/tracks/1/playlists',
                        ],
                        'data' => [['type' => 'playlists', 'id' => '00000000-0000-4000-8000-000000000001']],
                    ],
                ],
            ]);
    }

    // --- includes ------------------------------------------------------------

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aDefaultIncludeSurfacesTheRelatedResource(): void
    {
        // AlbumResource::getDefaultIncludedRelationships() returns ['artist'], so a
        // bare single-resource fetch carries the artist in the compound document.
        $included = $this->fetch('/albums/1')['included'] ?? null;
        self::assertIsArray($included);

        $index = $this->includedIndex($included);
        self::assertArrayHasKey('artists:1', $index);
        self::assertSame('Radiohead', $this->attribute($index, 'artists:1', 'name'));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anExplicitIncludeReplacesTheDefaultInclude(): void
    {
        // ?include=tracks surfaces the tracks and suppresses the default artist
        // include — an explicit include is authoritative.
        $document = $this->fetch('/albums/1?include=tracks');

        $included = $document['included'] ?? null;
        self::assertIsArray($included);

        $index = $this->includedIndex($included);
        self::assertArrayNotHasKey('artists:1', $index);
        self::assertArrayHasKey('tracks:1', $index);
        self::assertArrayHasKey('tracks:3', $index);
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aCollectionFetchWithIncludeRendersACompoundDocument(): void
    {
        // ?include=artist on the collection surfaces the distinct artists once each.
        $included = $this->fetch('/albums?include=artist&sort=title')['included'] ?? null;
        self::assertIsArray($included);

        $index = $this->includedIndex($included);
        self::assertArrayHasKey('artists:1', $index);
        self::assertArrayHasKey('artists:2', $index);
    }

    // --- page-size cap -------------------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function anOverLargePageSizeIsCappedNotHonoured(): void
    {
        // The example sets json_api.pagination.max_per_page: 50 (config/packages/
        // json_api.yaml), so the server default paginator clamps an abusive
        // page[size] to 50 — the page-size DoS vector is closed by the clamp, not a
        // 400. The whole request runs through the Doctrine provider as real DQL.
        $document = $this->fetch('/tracks?page[size]=1000000');

        // 200 with at most the cap's worth of items (here bounded by the 3 available).
        self::assertLessThanOrEqual(50, \count($this->trackIds($document)));

        $page = $this->pageMeta($document);
        self::assertSame(50, $page['perPage'] ?? null, 'meta.page.perPage reflects the cap, not 1000000');
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function anInRangePageSizeIsUnaffectedByTheCap(): void
    {
        // A size at or below the cap is honoured verbatim — the cap only clamps down.
        $document = $this->fetch('/tracks?page[size]=2&sort=trackNumber');

        self::assertCount(2, $this->trackIds($document));
        $page = $this->pageMeta($document);
        self::assertSame(2, $page['perPage'] ?? null);
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $path): array
    {
        $response = $this->handle($path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function pageMeta(array $document): array
    {
        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);

        $page = $meta['page'] ?? null;
        self::assertIsArray($page);

        /** @var array<string, mixed> $page */
        return $page;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function trackIds(array $document): array
    {
        return $this->idsOfType($document, 'tracks');
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function albumIds(array $document): array
    {
        return $this->idsOfType($document, 'albums');
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function idsOfType(array $document, string $type): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            self::assertSame($type, $resource['type'] ?? null);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function firstError(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $first = $errors[0] ?? null;
        self::assertIsArray($first);

        /** @var array<string, mixed> $first */
        return $first;
    }

    /**
     * @param array<mixed> $included
     *
     * @return array<string, array<string, mixed>>
     */
    private function includedIndex(array $included): array
    {
        $index = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            $type = $resource['type'] ?? null;
            $id = $resource['id'] ?? null;
            self::assertIsString($type);
            self::assertIsString($id);
            /** @var array<string, mixed> $resource */
            $index[$type . ':' . $id] = $resource;
        }

        return $index;
    }

    /**
     * The named attribute of the indexed resource keyed `"{type}:{id}"`.
     *
     * @param array<string, array<string, mixed>> $index
     */
    private function attribute(array $index, string $key, string $name): mixed
    {
        $attributes = $index[$key]['attributes'] ?? null;
        self::assertIsArray($attributes);

        return $attributes[$name] ?? null;
    }
}
