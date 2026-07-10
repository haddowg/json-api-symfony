<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApiBundle\DataProvider\RelationCriteriaFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The `belongsToMany` pivot acceptance suite (Doctrine only): the `playlists.tracks`
 * relation is backed by the {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PlaylistTrackEntity}
 * association entity (auto-detected), which carries the `position`/`addedAt` pivot
 * columns. It proves pivot values render as `meta.pivot` on BOTH the related
 * endpoint and the relationship-linkage endpoint, that `?sort`/`?filter` by a pivot
 * field order/narrow the collection, that a pivot filter composes with a related
 * filter in one correctly-paginated page, and the documented boundaries (a pivot
 * key is unrecognised on the primary collection; the in-memory provider has no
 * pivot at all — asserted in {@see InMemoryPivotBoundaryTest}).
 */
final class DoctrinePivotRelatedCollectionTest extends JsonApiFunctionalTestCase
{
    use SeedsDoctrinePivot;

    private const string BASE_URI = 'https://example.test';

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aPivotRelatedEndpointRendersPerMemberPivotMeta(): void
    {
        $document = $this->fetchDocument('/playlists/1/tracks?sort=position');

        self::assertSame(['1', '2', '3'], $this->ids($document));

        // Each member carries its own pivot values as meta.pivot, typed per the
        // declared fields (position is an int, addedAt an ISO-8601 string).
        $byId = $this->byId($document);
        self::assertSame(1, $this->pivotField($byId['1'] ?? [], 'position'));
        self::assertSame(2, $this->pivotField($byId['2'] ?? [], 'position'));
        self::assertSame(3, $this->pivotField($byId['3'] ?? [], 'position'));
        self::assertSame('2024-01-01T00:00:00+00:00', $this->pivotField($byId['1'] ?? [], 'addedAt'));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function withCountOnAPivotRelationCountsDistinctMembers(): void
    {
        // The pivot relation is countable(): ?withCount=tracks emits meta.total on
        // the tracks relationship object, counting DISTINCT far members. Playlist 1
        // has three distinct tracks (Intro@1, Outro@2, Bridge@3), so the total is 3.
        $document = $this->countsFetchDocument('/playlists/1?withCount=tracks');

        self::assertSame(3, $this->relationshipTotal($document, 'tracks'));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function withCountOnAPivotRelationMatchesTheEndpointTotalUnderDuplicateMembership(): void
    {
        // Playlist 3 carries duplicate membership (Intro at two positions + Outro):
        // three association rows over TWO distinct tracks. The ?withCount total counts
        // DISTINCT far members (2), so it agrees with the related-collection endpoint's
        // page total (also 2, see aPivotRelatedCollectionDedupesDuplicateMembership)
        // and the deduped rendered linkage — one consistent `total` semantic (ADR 0052).
        $document = $this->countsFetchDocument('/playlists/3?withCount=tracks');

        // The inline `?withCount=tracks` relationship-object count (the §6c path,
        // unchanged by G21) reports the DISTINCT far-member count (2), agreeing with the
        // deduped rendered linkage — one consistent `total` semantic (ADR 0052).
        self::assertSame(2, $this->relationshipTotal($document, 'tracks'));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRequestScopedCountHolderIsAResettableSharedService(): void
    {
        // The request-scoped count holder is a singleton, so a ?withCount read's counts
        // would otherwise survive into a later write/linkage render in a long-lived
        // container (a worker reusing the kernel). It is wired as a SHARED service that
        // implements ResetInterface, so framework autoconfiguration tags it kernel.reset
        // and the container clears it between messages. This asserts the wiring (a single
        // shared instance, resettable); the set/reset contract itself is covered by
        // RequestScopedRelationshipCountTest.
        $container = static::getContainer();

        $holder = $container->get(\haddowg\JsonApiBundle\Serializer\RequestScopedRelationshipCount::class);
        self::assertInstanceOf(\Symfony\Contracts\Service\ResetInterface::class, $holder);

        // The same shared instance is handed back each time — a per-request reset of the
        // one holder is what clears a prior request's counts (a non-shared holder would
        // never see the leak in the first place, but the count seam needs the one Server
        // to render through it).
        self::assertSame($holder, $container->get(\haddowg\JsonApiBundle\Serializer\RequestScopedRelationshipCount::class));
    }

    /**
     * The `meta.total` of the primary resource's named relationship object.
     *
     * @param array<string, mixed> $document
     */
    private function relationshipTotal(array $document, string $name): int
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);

        $relationship = $relationships[$name] ?? null;
        self::assertIsArray($relationship);

        $meta = $relationship['meta'] ?? null;
        self::assertIsArray($meta);

        $total = $meta['total'] ?? null;
        self::assertIsInt($total);

        return $total;
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aPivotRelationshipEndpointRendersPerMemberLinkageMeta(): void
    {
        $document = $this->fetchDocument('/playlists/1/relationships/tracks');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        // The linkage identifiers each carry meta.pivot for their member, riding
        // core's identifier-meta render path (no attributes — linkage only). Pair the
        // id with its pivot position in document order.
        $byIdPosition = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            self::assertArrayNotHasKey('attributes', $identifier);
            $id = $identifier['id'] ?? null;
            self::assertIsString($id);
            $byIdPosition[] = [$id, $this->pivotField($identifier, 'position')];
        }

        \usort($byIdPosition, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        self::assertSame([['1', 1], ['2', 2], ['3', 3]], $byIdPosition);
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aPivotSortOrdersTheRelatedCollectionAndFlips(): void
    {
        $ascending = $this->fetchDocument('/playlists/1/tracks?sort=position');
        self::assertSame(['1', '2', '3'], $this->ids($ascending));

        // The order flips under -position: the same membership, reversed.
        $descending = $this->fetchDocument('/playlists/1/tracks?sort=-position');
        self::assertSame(['3', '2', '1'], $this->ids($descending));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aPivotSortPrecedesARelatedSortInRequestOrder(): void
    {
        // `?sort=position,title` orders by the PIVOT key first: position ascends
        // Intro(1), Outro(2), Bridge(3).
        $pivotFirst = $this->fetchDocument('/playlists/1/tracks?sort=position,title');
        self::assertSame(['1', '2', '3'], $this->ids($pivotFirst));

        // `?sort=title,position` orders by the RELATED key first: title ascends
        // Bridge(3), Intro(1), Outro(2) — a different order. Before the fix BOTH
        // requests returned this list, because the shared applier appended every
        // related sort before any pivot sort, silently demoting a pivot-first sort.
        $relatedFirst = $this->fetchDocument('/playlists/1/tracks?sort=title,position');
        self::assertSame(['3', '1', '2'], $this->ids($relatedFirst));

        // The two orders genuinely differ — the request directive order is honoured
        // across both aliases, not flattened to related-then-pivot.
        self::assertNotSame($this->ids($pivotFirst), $this->ids($relatedFirst));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aPivotFilterNarrowsTheRelatedCollection(): void
    {
        // filter[position]=2 keeps only the member at pivot position 2 (Outro, id 2).
        $document = $this->fetchDocument('/playlists/1/tracks?filter[position]=2');

        self::assertSame(['2'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function anEqualityPivotFilterIsBehaviourIdentical(): void
    {
        // Regression guard: routing the equality Where through the shared handler is
        // behaviour-identical to the old hand-rolled applier — filter[position]=2 still
        // keeps only the member at pivot position 2 (Outro, id 2).
        $document = $this->fetchDocument('/playlists/1/tracks?filter[position]=2');

        self::assertSame(['2'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aPivotFilterAttachesTheFieldCastDeserializer(): void
    {
        // Cast-thread guard (bundle ADR 0067): a pivot filter is AUTHOR-declared with a
        // `pivot.`-prefixed column and carries NO explicit deserializer; the
        // RelationCriteriaFactory must auto-attach the field's own cast (resolved by the
        // STRIPPED column, not the filter key) so the handler binds a typed value — a
        // bare Where::make() leaves deserialize null, which would silently regress a
        // typed pivot column (a DateTime/bool would bind the raw request string; an int
        // survives only via DQL's type coercion). The filter KEY is independent of the
        // column: `addedAfter` filters `pivot.addedAt`, resolved by column.
        $relation = BelongsToMany::make('tracks', 'tracks')
            ->fields(
                Integer::make('position')->required()->min(1)->build(),
                DateTime::make('addedAt')->readOnly()->build(),
            )
            ->withFilters(
                // No explicit deserializer — the factory resolves the cast by the
                // stripped column (`position`/`addedAt`).
                Where::make('position', 'pivot.position'),
                Where::make('addedAfter', 'pivot.addedAt', '>'),
                // An author's explicit deserializer must WIN — never overwritten.
                Where::make('weightExplicit', 'pivot.weight')->deserializeUsing(static fn(mixed $v): string => 'kept'),
            );

        $factory = new RelationCriteriaFactory();
        $params = new QueryParameters([], [], [], [], []);

        // With includePivotFields=true (the Doctrine related endpoint) the relation's
        // pivot filters ride the criteria, the cast auto-attached.
        $withPivot = $factory->criteriaFor($params, null, $relation, null, true);
        $byKey = $this->filtersByKey($withPivot->filters);

        // `position` (int) coerces the raw request string '2' to int 2 via the field's
        // OWN cast — the exact typed-bind the thread guards against.
        $position = $byKey['position'] ?? null;
        self::assertInstanceOf(Where::class, $position);
        self::assertNotNull($position->deserialize, 'an author pivot filter must carry the resolved field cast');
        self::assertSame(2, ($position->deserialize)('2'));

        // `addedAfter` resolves the cast by the STRIPPED column `addedAt` (the key
        // differs from the column): the DateTime field coerces the wire value to an
        // ISO-8601 string, so a non-null cast is attached.
        $addedAfter = $byKey['addedAfter'] ?? null;
        self::assertInstanceOf(Where::class, $addedAfter);
        self::assertNotNull($addedAfter->deserialize, 'the cast resolves by the stripped column, not the filter key');

        // The author's explicit deserializer wins — it is not overwritten by the cast.
        $weightExplicit = $byKey['weightExplicit'] ?? null;
        self::assertInstanceOf(Where::class, $weightExplicit);
        self::assertNotNull($weightExplicit->deserialize);
        self::assertSame('kept', ($weightExplicit->deserialize)('anything'));

        // With includePivotFields=false (the in-memory provider + the include/count
        // Doctrine paths) every `pivot.`-columned filter is STRIPPED from the criteria,
        // so a pivot key stays unrecognised and never routes a `pivot.`-column to the
        // wrong root.
        $withoutPivot = $factory->criteriaFor($params, null, $relation, null, false);
        $strippedKeys = \array_map(static fn(FilterInterface $f): string => $f->key(), $withoutPivot->filters);
        self::assertNotContains('position', $strippedKeys);
        self::assertNotContains('addedAfter', $strippedKeys);
        self::assertNotContains('weightExplicit', $strippedKeys);
        self::assertSame([], $withoutPivot->aliasOf, 'no pivot aliasOf off the pivot path');
    }

    /**
     * @param list<FilterInterface> $filters
     *
     * @return array<string, FilterInterface>
     */
    private function filtersByKey(array $filters): array
    {
        $byKey = [];
        foreach ($filters as $filter) {
            $byKey[$filter->key()] = $filter;
        }

        return $byKey;
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aPivotFilterComposesWithARelatedFilterInOnePage(): void
    {
        // filter[title] (the related `tracks` vocabulary, contains "o" → Intro,
        // Outro, NOT Bridge) composes with the pivot sort in ONE query; a page of
        // size 2 holds both, so the page is full (no short page). Count-free by
        // default (G21): the page carries no total, and with both matches on page 1
        // there is no further page (no `next`).
        $document = $this->fetchDocument('/playlists/1/tracks?filter[title]=o&sort=position&page[size]=2&page[number]=1');

        self::assertSame(['1', '2'], $this->ids($document));

        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertArrayHasKey('page', $meta);
        $page = $meta['page'];
        self::assertIsArray($page);
        self::assertArrayNotHasKey('total', $page, 'count-free by default: no page total');

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertArrayNotHasKey('next', $links, 'both matches are on page 1, so no further page');
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aPivotOperatorFilterNarrowsTheRelatedCollection(): void
    {
        // An author-declared pivot OPERATOR filter (bundle ADR 0067): `positionGte`
        // targets `pivot.position` with the `>=` operator. filter[positionGte]=2 keeps
        // the members at pivot position >= 2 (Outro@2, Bridge@3), ordered by position.
        $document = $this->fetchDocument('/playlists/1/tracks?filter[positionGte]=2&sort=position');

        self::assertSame(['2', '3'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aPivotWhereInFilterNarrowsTheRelatedCollection(): void
    {
        // An author-declared pivot WhereIn filter (bundle ADR 0067): `positionIn`
        // targets `pivot.position`. filter[positionIn]=1,3 keeps the members at pivot
        // position 1 or 3 (Intro@1, Bridge@3), ordered by position.
        $document = $this->fetchDocument('/playlists/1/tracks?filter[positionIn]=1,3&sort=position');

        self::assertSame(['1', '3'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aTypedDatePivotOperatorFilterNarrowsTheRelatedCollection(): void
    {
        // An author-declared TYPED-DATE pivot operator filter (bundle ADR 0067):
        // `addedAfter` targets `pivot.addedAt` with `>`, and the DateTime field's cast
        // auto-resolves by the stripped column so the wire value is compared as a date.
        // Playlist 1's rows are added 2024-01-01/02/03; > 2024-01-01 keeps Outro(2) and
        // Bridge(3), ordered by position.
        $document = $this->fetchDocument('/playlists/1/tracks?filter[addedAfter]=2024-01-01T00:00:00%2B00:00&sort=position');

        self::assertSame(['2', '3'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aPivotRelatedCollectionDedupesDuplicateMembership(): void
    {
        // Playlist 3 has Intro at two positions and Outro at one — three association
        // rows, two distinct far members. The page must group to one row per member,
        // so the total is two (not three) and the collection holds two distinct ids.
        $document = $this->fetchDocument('/playlists/3/tracks?sort=position');

        self::assertSame(['1', '2'], $this->ids($document));

        // page[size]=1 must hand back ONE distinct member per page and never repeat a
        // member across pages — the duplicate rows must not split a member's window.
        $first = $this->fetchDocument('/playlists/3/tracks?sort=position&page[size]=1&page[number]=1');
        $second = $this->fetchDocument('/playlists/3/tracks?sort=position&page[size]=1&page[number]=2');

        $firstIds = $this->ids($first);
        $secondIds = $this->ids($second);
        self::assertCount(1, $firstIds);
        self::assertCount(1, $secondIds);
        self::assertNotSame($firstIds, $secondIds, 'a member was duplicated across pages');
        self::assertSame(['1', '2'], [...$firstIds, ...$secondIds]);

        // Page 1 of two distinct members signals a further page via `next` (count-free
        // by default, G21); the dedup holds without a total being needed.
        $links = $first['links'] ?? null;
        self::assertIsArray($links);
        self::assertNotNull($links['next'] ?? null, 'a second distinct member follows on page 2');
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aNonCountablePivotRelatedEndpointPaginatesCountFree(): void
    {
        // `orderedTracks` is the SAME association entity as `tracks` but left
        // NON-countable, so its related endpoint paginates count-free (bundle ADR
        // 0052): page one of two over playlist 1's three distinct members holds two
        // items, signals a further page through `next`, and emits NO total and NO
        // `last` — the universal countable() gate reaches the pivot path.
        $document = $this->fetchDocument('/playlists/1/orderedTracks?sort=position&page[size]=2&page[number]=1');

        self::assertSame(['1', '2'], $this->ids($document));

        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        $page = $meta['page'] ?? null;
        self::assertIsArray($page);
        self::assertArrayNotHasKey('total', $page, 'a non-countable pivot endpoint must not COUNT');

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertArrayHasKey('next', $links, 'a further page is signalled by `next`');
        self::assertArrayNotHasKey('last', $links, 'a count-free page has no `last` link');

        // Each member still carries its pivot meta — the count-free path renders the
        // same pivot values, only without the page total.
        $byId = $this->byId($document);
        self::assertSame(1, $this->pivotField($byId['1'] ?? [], 'position'));
        self::assertSame(2, $this->pivotField($byId['2'] ?? [], 'position'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aHiddenPivotFieldFiltersButIsNotRendered(): void
    {
        // `note` is a HIDDEN pivot field (core hidden() gates rendering only, never
        // query): it is filterable via the `pivot.note` prefix, so filter[noteIs]=alpha
        // narrows playlist 1 to the member at note 'alpha' (Intro, id 1) — the filter
        // reads the column on the `pivot` alias, not the rendered scalar.
        $document = $this->fetchDocument('/playlists/1/tracks?filter[noteIs]=alpha');

        self::assertSame(['1'], $this->ids($document));

        // The rendered pivot meta on that member does NOT contain `note` — a hidden
        // pivot field is omitted from the SELECT and the pivot map, so only the visible
        // fields (position, addedAt, weight) render.
        $byId = $this->byId($document);
        $pivot = $this->pivotMap($byId['1'] ?? []);
        self::assertArrayNotHasKey('note', $pivot, 'a hidden pivot field must not render in the pivot meta');
        self::assertArrayHasKey('position', $pivot);
        self::assertArrayHasKey('addedAt', $pivot);
        self::assertArrayHasKey('weight', $pivot);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aPivotRelatedCollectionScopesToItsParent(): void
    {
        // Playlist 2 shares Intro (id 1) only — per-parent scoping must not bleed
        // playlist 1's rows in.
        $document = $this->fetchDocument('/playlists/2/tracks?sort=position');

        self::assertSame(['1'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aPivotKeyIsUnrecognisedOnThePrimaryCollection(): void
    {
        // `position` is a pivot key, scoped to the related endpoint only; on the
        // primary /tracks collection it is undeclared → 400.
        $response = $this->handle(self::BASE_URI . '/tracks?filter[position]=1');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aPivotSortKeyIsUnrecognisedOnThePrimaryCollection(): void
    {
        $response = $this->handle(self::BASE_URI . '/tracks?sort=position');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function anUnknownKeyStill400sOverTheUnifiedPivotVocabulary(): void
    {
        // The unified alias-aware applier owns the WHOLE merged related+pivot vocab in
        // one pass (bundle ADR 0059), so an undeclared key in NEITHER the related nor
        // the pivot vocabulary is still a 400 on the pivot related endpoint.
        $unknownFilter = $this->handle(self::BASE_URI . '/playlists/1/tracks?filter[nope]=x');
        self::assertSame(400, $unknownFilter->getStatusCode(), (string) $unknownFilter->getContent());

        $unknownSort = $this->handle(self::BASE_URI . '/playlists/1/tracks?sort=nope');
        self::assertSame(400, $unknownSort->getStatusCode(), (string) $unknownSort->getContent());
    }

    // --- helpers ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function fetchDocument(string $path): array
    {
        $response = $this->handle(self::BASE_URI . $path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->decode($response);
    }

    /**
     * A `?withCount` fetch: negotiates the Relationship Counts profile its family is
     * gated behind (the response then advertises it, so the Content-Type is not the
     * bare media type checked by {@see fetchDocument()}).
     *
     * @return array<string, mixed>
     */
    private function countsFetchDocument(string $path): array
    {
        $response = $this->handle(self::BASE_URI . $path, extraServer: [
            'HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . CountableProfile::URI . '"',
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
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
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * The named pivot value under a resource/identifier's `meta.pivot`, or null when
     * absent — a typed extraction so the assertions stay PHPStan-clean.
     *
     * @param array<string, mixed> $resource
     */
    private function pivotField(array $resource, string $field): mixed
    {
        $meta = $resource['meta'] ?? null;
        if (!\is_array($meta)) {
            return null;
        }

        $pivot = $meta['pivot'] ?? null;
        if (!\is_array($pivot)) {
            return null;
        }

        return $pivot[$field] ?? null;
    }

    /**
     * The whole `meta.pivot` map of a resource, or an empty array when absent — used to
     * assert which pivot fields are present (a hidden field must be absent).
     *
     * @param array<string, mixed> $resource
     *
     * @return array<string, mixed>
     */
    private function pivotMap(array $resource): array
    {
        $meta = $resource['meta'] ?? null;
        if (!\is_array($meta)) {
            return [];
        }

        $pivot = $meta['pivot'] ?? null;
        if (!\is_array($pivot)) {
            return [];
        }

        /** @var array<string, mixed> $pivot */
        return $pivot;
    }

    /**
     * The primary data resources keyed by id.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, array<string, mixed>>
     */
    private function byId(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $byId = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            /** @var array<string, mixed> $resource */
            $byId[$id] = $resource;
        }

        return $byId;
    }
}
