# Relationship endpoints in the bundle

The core library owns the relation DSL — `BelongsTo`/`HasMany`/`BelongsToMany`/`MorphTo`
(`HasMany` is the plain to-many, `BelongsToMany` the pivot-backed to-many — see
[core relations](https://github.com/haddowg/json-api/blob/main/docs/relations.md)),
the `type()`/`paginate()`/`linkageOnlyWhenLoaded()` builders, the
`withoutLinks()`/`cannotReplace()` exposure flags, and the rendering of linkage,
`self`/`related` links and `?include`. Read
[core relations](https://github.com/haddowg/json-api/blob/main/docs/relations.md)
and [core related-endpoints](https://github.com/haddowg/json-api/blob/main/docs/related-endpoints.md)
for that vocabulary.

This page covers what the **bundle** adds: the Symfony routes for each relation,
the handler-side enforcement of per-relation exposure gates, the
storage-correct mutation seam, the queryable/paginated related-collection seam,
and how polymorphic and resource-less relations wire up. Every relation you
declare on a resource — or standalone via [`#[AsJsonApiRelations]`](#relations-without-a-resource) —
gets the full relationship endpoint set automatically, with no extra routing.

## The two read endpoints per relation

For any type that declares relations, the bundle's [route loader](routing.md)
emits two read paths per relation, both parametric in `{relationship}` (written
`{rel}` for short in the path tables below):

| Endpoint | Path | Renders |
|----------|------|---------|
| related | `GET /{type}/{id}/{rel}` | the related domain value(s) as full resources |
| relationship (linkage) | `GET /{type}/{id}/relationships/{rel}` | resource-identifier objects only |

Linkage and the convention `self`/`related` links render on the parent's own
read too (`GET /tracks/1`), default on. A to-one with no related object renders
`data: null` (not a 404), and `?include` flows through both the parent read and
the related endpoint. The relation DSL drives all of this — see
[core relations](https://github.com/haddowg/json-api/blob/main/docs/relations.md)
(`withoutLinks()`) and
[core sparse-fieldsets-and-includes](https://github.com/haddowg/json-api/blob/main/docs/sparse-fieldsets-and-includes.md)
(`?include`).

The bundle's job is the wiring. [`TrackResource`](../examples/music-catalog-symfony/src/Resource/TrackResource.php)
declares a to-one `album` and a **plain** to-many `playlists` (the pivot-bearing
variant lives on the playlists resource's `orderedTracks` — see
[Pivot (`belongsToMany`) data](#pivot-belongstomany-data)):

```php
BelongsTo::make('album')->type('albums'),
BelongsToMany::make('playlists')
    ->type('playlists')
    ->cannotReplace()
    ->countable(),
```

and `GET /tracks/1` renders each relationship with linkage plus the two
convention links, exactly as the
[`RelationshipReadTest`](../examples/music-catalog-symfony/tests/RelationshipReadTest.php)
asserts:

```jsonc
"relationships": {
  "album": {
    "data": { "type": "albums", "id": "1" },
    "links": {
      "self": "https://music.example/tracks/1/relationships/album",
      "related": "https://music.example/tracks/1/album"
    }
  }
}
```

### Load-state-aware linkage

A relation may opt into `linkageOnlyWhenLoaded()` so a lazy to-many renders the
convention links **without** forcing a fetch. On the Doctrine path the bundle
backs this with a storage-aware load-state seam (`DoctrineRelationshipLoadState`,
owned by [doctrine.md](doctrine.md)): an uninitialised `PersistentCollection`
reports "not loaded", so the rendered relationship carries `links` but omits the
`data` member.
[`AlbumResource`](../examples/music-catalog-symfony/src/Resource/AlbumResource.php)
declares `HasMany::make('tracks')->…->linkageOnlyWhenLoaded()`, and `GET /albums/1`
renders `tracks` with links and no `data`, while the explicit
`GET /albums/1/relationships/tracks` materialises the full identifier list.

## Queryable, paginated related collections

`GET /{type}/{id}/{rel}` for a to-many is a **real collection endpoint**: it
honours `?filter`/`?sort`/`?page` against the **related** type's vocabulary, not
the parent's. The bundle resolves this through a dedicated SPI seam,
`DataProvider::fetchRelatedCollection()` (signature in
[data-layer.md](data-layer.md)) — so a custom provider can scope or replace it,
and the Doctrine reference never loads the whole collection.

Per-relation default pagination resolves along a chain: the relation's own
paginator → the related resource's paginator → the server default. The Doctrine
push-down (FK fast-path vs `IN`-subquery for many-to-many) is owned by
[doctrine.md](doctrine.md); the in-memory provider reads the related objects off
the parent and applies the shared `CriteriaApplier` plus an array window.

The [`RelatedCollectionTest`](../examples/music-catalog-symfony/tests/RelatedCollectionTest.php)
witnesses both branches. `albums.tracks` paginates two-per-page (declared on the
album's `HasMany('tracks')->paginate(PagePaginator::make()->withDefaultPerPage(2))`),
and `?filter`/`?sort` scope against the `tracks` vocabulary — so the related
type's own default filter even hides the explicit track:

```
GET /albums/1/tracks                  → tracks 1, 3 (track 2 is explicit, filtered out), page meta
GET /albums/1/tracks?sort=-title      → tracks 3, 1
GET /albums/1/tracks?filter[explicit]=true → track 2
GET /tracks/1/playlists               → unpaginated (the relation declares no paginator)
```

A relation that declares no paginator renders its related collection without page
meta — that is the unpaginated baseline.

### Filtering a to-one relationship

A to-one is not a collection, so it does not paginate or sort — but it can carry a
relation-scoped `?filter` that decides whether its target is **rendered at all**. Give
a to-one relation a `withFilters(...)` over a column on its related type, and the
filter applies as a predicate on the target: when it **excludes** the target, the
to-one renders `data: null` instead of the resource (bundle ADR 0068). A `200` with
`data: null`, never a `404` — the relationship exists, the filter just matched nothing.

[`AlbumResource`](../examples/music-catalog-symfony/src/Resource/AlbumResource.php)
scopes `filter[name]` (the related `artists.name` column) onto its `artist` to-one:

```php
BelongsTo::make('artist')
    ->type('artists')
    ->withFilters(Where::make('name', 'name')),
```

The filter reaches the to-one on all three read surfaces, each nulling the target when
it doesn't match (album 1 belongs to Radiohead):

```
# related endpoint — full resource, or data: null
GET /albums/1/artist?filter[name]=Radiohead   → data: { type: artists, id: 1, … }
GET /albums/1/artist?filter[name]=Portishead  → { "data": null }   (200, not 404)

# relationship (linkage) endpoint — identifier, or data: null
GET /albums/1/relationships/artist?filter[name]=Radiohead   → data: { type: artists, id: 1 }
GET /albums/1/relationships/artist?filter[name]=Portishead  → { "data": null }
```

The third surface is the **Relationship Queries profile** on a primary request: a
`relatedQuery[<toOneRel>][filter]` that excludes the target renders the linkage `null`
**and drops the target from `included`**:

```
GET /albums/1?include=artist&relatedQuery[artist][filter][name]=Radiohead
  → data.relationships.artist.data = { type: artists, id: 1 }, included = [artist 1]
GET /albums/1?include=artist&relatedQuery[artist][filter][name]=Portishead
  → data.relationships.artist.data = null, included = []
```

`relatedQuery` on a to-one is **`[filter]` only** — a `[sort]` or `[page]` on a to-one
path is a `400` (a to-one is not a list); see the profile section's
[to-one bullet](#filtering-and-sorting-a-relationship-from-the-primary-request-the-relationship-queries-profile).
The custom-provider seams behind this are `relatedToOneMatches()` /
`relatedToOneMatchesBatch()` on
[`DataProviderInterface`](../src/DataProvider/DataProviderInterface.php) (returning
`false` nulls the target; the batched twin answers a whole page of parents at once so
an include does not N+1); the feature is monomorphic — a `MorphTo` to-one is out of
scope (ADR 0068). The
[`RelationshipReadTest`](../examples/music-catalog-symfony/tests/RelationshipReadTest.php)
and [`RelationshipQueriesProfileTest`](../examples/music-catalog-symfony/tests/RelationshipQueriesProfileTest.php)
witness all three surfaces on Doctrine.

### Counting relations (`countable()` and `?withCount`)

Mark a to-many relation `countable()` to opt it into counting (off by default,
core ADR 0057; bundle ADR 0052). The count is pushed down (never materialising the
collection) and **batched** across a page of parents (one grouped count per
relation, no N+1):

```php
HasMany::make('tracks')->type('tracks')->countable();
```

Counting is exposed two ways, both on the `total` meta key:

- **`?withCount=rel1,rel2`** — a flat primary-request parameter naming
  relationships (like `?include`) — adds `meta.total` to each named relationship
  **object** when the parent is rendered (a single resource, every parent of a
  collection, and a related-collection member):

  ```
  GET /albums/1?withCount=tracks         → data.relationships.tracks.meta.total
  GET /albums?withCount=tracks           → every album's tracks.meta.total (counted in ONE grouped query)
  ```

  The total is gated by `countable()` **and** being named in `?withCount`. A
  `?withCount` naming a relation that is not `countable()`, naming a to-one, or
  naming an unknown relationship is a `400` (`source.parameter: withCount`).

- **The related-collection endpoint** (`GET /{type}/{id}/{rel}`) is gated by
  `countable()` **alone**. A countable relation's endpoint emits `meta.page.total`
  + a `last` link; a **non-countable** relation's endpoint paginates **count-free**
  — no `total`, no `last`, and a further page is signalled by `next` being present
  (no `COUNT` query runs). This is how a related collection paginates without paying
  for a count. The gate is universal — it applies to a pivot (`belongsToMany`)
  relation's endpoint exactly as to a plain one (a non-countable pivot endpoint runs
  no count over its association entity).

> **Behaviour change.** Related collections used to always emit a total. A relation
> whose endpoint should keep one must now be `countable()`. Leaving a relation
> non-countable is the way to get count-free related pagination.

**`?withCount` counts the relation's filtered set.** The count reflects the SAME
filters the related-collection endpoint applies (bundle ADR 0060): a relation's own
`filters()`, the related resource's filter **defaults**, and any
`relatedQuery[<rel>][filter]` the request carries through the Relationship Queries
profile. So `?withCount=tracks` of a relation whose related resource defaults
`explicit=false` reports the default-scoped total, and
`?withCount=tracks&relatedQuery[tracks][filter][explicit]=true` counts only the
matching members — exactly the totals `GET /albums/{id}/tracks` (with the same filter)
pages. A parent with no matching member reports `0`. The common case — a relation with
no filter and no filter defaults, with no relatedQuery filter — is raw membership,
unchanged.

> **Behaviour change.** `?withCount` used to count raw membership and ignore any
> active relation filter. It now counts the **filtered** set, so a relationship
> object's `meta.total` can be smaller than in prior releases — it now equals the
> related endpoint's filtered total. This includes filter **defaults**: a related
> resource with a default filter narrows the count even without an explicit
> relatedQuery filter.

A pivot (`belongsToMany`) relation counts **distinct far members** — so duplicate
membership (the same member joined to the parent by more than one association row, a
track at two positions) counts **once**, matching the related-collection endpoint
(which dedupes to one row per member) and the rendered linkage. The `?withCount`
relationship-object total and the endpoint pagination total therefore report the same
number for the same relation/parent. A polymorphic `MorphToMany` is counted by the
in-memory provider (the mixed member set) but is unsupported by the Doctrine
reference provider (its members span entity classes) — supply a custom
`DataProvider` if you need it on Doctrine.

### Relation-scoped filters and sorts

A relation can declare **its own** `filters()`/`sorts()` that augment **only** its
related-collection endpoint `GET /{type}/{id}/{rel}` — never the primary
`/{relatedType}` collection. Declare them with the relation builders
`withFilters(...)`/`withSorts(...)`:

```php
HasMany::make('tracks')->type('tracks')
    // Available ONLY on GET /playlists/{id}/tracks, not on GET /tracks:
    ->withFilters(Where::make('titleContains', 'title', 'like'))
    ->withSorts(SortByField::make('recent', 'id'));
```

The bundle merges this scoped vocabulary with the related resource's own when it
parses the request's `?filter`/`?sort` on the related endpoint:

- `effectiveFilters = relatedResource->filters() + relation->filters()`
- `effectiveSorts   = relatedResource->allSorts() + relation->sorts()`

so both apply together. On a **key clash** (the same `filter[…]`/`sort` key declared
on both the related resource and the relation) the **relation's declaration wins**
— it is the more specific scope. A key in **neither** set still `400`s
(`filter[unknown]` → an unrecognized-parameter error; `sort=unknown` →
`SORTING_UNRECOGNIZED`), unchanged.

The scoping is load-bearing: a relation-scoped key reaches `GET /{type}/{id}/{rel}`
**only**. On the primary `/{relatedType}` collection — where just the related
resource's own vocabulary applies — the relation's key is unrecognized and `400`s.
This is why a contextual or pivot-derived filter/sort belongs on the relation: it
stays scoped to the one endpoint where it is meaningful.

A relation-scoped filter/sort operates on the **related entity** (the common case)
and works out of the box. A filter/sort that reads a **pivot/join-table column** is
handled separately — see [Pivot (`belongsToMany`) data](#pivot-belongstomany-data)
below.

### Filtering and sorting a relationship from the primary request (the Relationship Queries profile)

The same scoped vocabulary is reachable from the **primary** request — without a
second round-trip to the related endpoint — through the **Relationship Queries**
profile. The bundle registers and advertises it, so a client opts in by negotiating
its URI in the `Accept` header's `profile` media-type parameter:

```
Accept: application/vnd.api+json;profile="https://haddowg.dev/profiles/relationship-queries"
```

With the profile negotiated, the client addresses a relationship by its **include
path** and supplies a per-relationship `sort` / `filter` via the `relatedQuery`
family (or the `rQ` shorthand; both are spec-compliant because each base carries an
uppercase letter):

```
# order the included tracks by -duration, narrow them to one filter
GET /albums/1?include=tracks&relatedQuery[tracks][sort]=-duration&relatedQuery[tracks][filter][longerThan]=300

# the rQ shorthand is identical
GET /albums/1?include=tracks&rQ[tracks][sort]=duration
```

- The client addresses a **top-level** relation of the request's primary resource by
  its name. A dotted path (`relatedQuery[tracks.album]`) is legal family **grammar** —
  it parses without error — but the bundle windows only top-level relations, so a
  dotted path addressing a relation of an *included* resource resolves to no relation
  and is a `400` (address that relation at its own endpoint instead). An **unknown
  relationship path** is likewise the related-collection endpoint's same `400`, with
  `source.parameter` the canonical `relatedQuery[<path>]`. A **to-one path** accepts
  **`[filter]` only** — a `[filter]` passes through and may null the linkage (see
  [Filtering a to-one relationship](#filtering-a-to-one-relationship)), while a
  `[sort]` or `[page]` on a to-one is the same `400` (a to-one is not a list).
- **`sort`** orders the relationship's linkage `data` (and so SELECTS which members
  land on the included page — see below). **`filter`** narrows the set against the
  **related-collection endpoint's vocabulary** (the related resource's filters/sorts
  merged with the relation's own scoped ones, exactly as above) — an unknown **key** is
  the same `400`.
- **`page` is out** for the profile: an addressed relationship always renders **page
  1** (the relation's default size), navigated via its own relationship-object
  pagination links. So a rendered to-many under the profile carries
  `first`/`prev`/`next` (+`last` only when the relation is `countable()`) in its
  `links` object — in the spec's **plain form** against the relationship-linkage
  endpoint (`/albums/1/relationships/tracks?sort=-duration&page[number]=2`), never the
  `relatedQuery[…]` form (which only addresses a relationship from a *parent* request).
- On a `[path][op]` **conflict** between `relatedQuery` and `rQ`, the canonical
  `relatedQuery` wins (the shorthand yields — not an error).
- The family is **ignored entirely** when the profile is not negotiated (today's
  behaviour, no profile advertised) — so a relationship literally named after a
  reserved query family is safe.

Under `?include`, the included resources reflect exactly that page-1 set — the `sort`
selects the page. On a **collection** include (`GET /albums?include=tracks`) each
parent's relationship is windowed to its own page 1 independently. Each to-many maps
to a distinct association, so this is unambiguous; if two relations alias one storage
column with different pagination, the rendered linkage is last-writer-wins on that
column (bundle ADR 0053).

Includes are **batch-loaded built in** — no extra dependency. The effective include
tree is loaded one query per relation per level (no `1 + N`) on both providers,
driven by the same batched related-fetch seam these windowed includes use; see
[doctrine → eager-loading includes](doctrine.md#eager-loading-includes-no-n1).

## Filtering a collection by a relationship

The sections above filter a relationship's **own** related collection. This one is
the inverse: filtering a **primary** collection by a condition on a relationship —
"albums whose artist is named Radiohead", "albums that have at least one track". The
core relation-existence filters (`WhereHas`/`WhereDoesntHave`) and the dotted
traversal filter (`WhereThrough`) declare on the resource's `filters()` like any
other filter and apply to its primary `GET /{type}` collection.

All three translate to a single correlated `EXISTS` (or `NOT EXISTS`) **semi-join**:
set-membership, never a fetch-join, so the primary rows are neither duplicated nor in
need of `DISTINCT`, the relation is never hydrated, and linkage / `?include` / the
relationship-queries profile all compose for free. A to-one and a to-many translate
identically — "there exists a related row that …".

### `WhereThrough` — dotted-path traversal (portable)

`WhereThrough::make('artist.name')` keeps a row whose **`artist` relation's `name`**
matches — an **EXISTS-ANY** semi-join across the dotted path. Every intermediate
segment is a relationship (to-one or to-many, both identical); the final segment is
the compared attribute. The path chains: `WhereThrough::make('author.company.name')`
hops two relations to a leaf column.

```php
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereHas;

public function filters(): array
{
    return [
        WhereHas::make('tracks'),       // albums that have at least one track
        WhereThrough::make('artist.name'), // filter[artist.name]=Radiohead
    ];
}
```

The wire key carries the dots by default — `make('artist.name')` responds to
`filter[artist.name]` — or pass an explicit key (`make('topArtist', 'artist.name')`
→ `filter[topArtist]`). Because both positional slots are taken, the comparison
operator is the fluent `->operator(...)` setter (default `=`, same vocabulary as
`Where`: `=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`, `like`), and `WhereThrough` carries
value constraints (`->integer()`, etc.) like any value filter — so a mistyped
`filter[…]` is a clean `400` (`FILTER_VALUE_INVALID`) before the provider runs.

It is **portable** — it works on both providers. The in-memory provider traverses
the object graph (core's `ArrayFilterHandler`); the Doctrine reference renders it as
a correlated `EXISTS` subquery rooted on the related entity. [`AlbumResource`](../examples/music-catalog-symfony/src/Resource/AlbumResource.php)
declares it, and [`ReadQueryTest::aWhereThroughFilterTraversesTheArtistRelationByName`](../examples/music-catalog-symfony/tests/ReadQueryTest.php)
witnesses it:

```
GET /albums?filter[artist.name]=Radiohead   → albums by Radiohead
GET /albums?filter[artist.name]=Portishead  → albums by Portishead
GET /albums?filter[artist.name]=Nobody      → data: []
```

### `WhereHasMatching` — the Doctrine escape hatch (not portable)

When the inner predicate is more than a single column comparison — a multi-column /
OR / NOT condition, or raw DQL — reach for the bundle-only
[`WhereHasMatching`](../src/DataProvider/Doctrine/Filter/WhereHasMatching.php)
(`haddowg\JsonApiBundle\DataProvider\Doctrine\Filter\`). It is a single relationship
hop whose related root you narrow yourself, with two surfaces:

```php
use haddowg\JsonApiBundle\DataProvider\Doctrine\Filter\WhereHasMatching;
use Doctrine\Common\Collections\Criteria;

public function filters(): array
{
    return [
        // structured: apply a Criteria (AND/OR/NOT over the related entity) on the
        // related root, responding to filter[hitTracks]
        WhereHasMatching::criteria(
            'hitTracks',
            'tracks',
            Criteria::create()->where(Criteria::expr()->gt('plays', 100)),
        ),
        // raw hatch: a closure given the related-rooted subquery, the related
        // alias, and the request value — you add predicates and bind parameters
        WhereHasMatching::using('namedLike', 'tracks', static function ($sub, $alias, $value): void {
            $sub->andWhere("$alias.title LIKE :q")->setParameter('q', "%$value%");
        }),
    ];
}
```

Two boundaries set it apart from `WhereThrough`:

- **Doctrine-only.** It lives in the Doctrine namespace and is recognised only by the
  Doctrine handler. On the in-memory provider the same `filter[<key>]` key is
  undeclared, so the request is a clean `400` (the unrecognised-filter boundary) —
  never a silent non-match.
- **Not value-validated.** The author owns the request value (the closure consumes it,
  no declared operator compares it), so `constraints()` returns `[]` — there is
  nothing for the validator bridge to check.

Both `WhereThrough` and `WhereHasMatching` share the one `EXISTS` builder in
[`DoctrineFilterHandler`](../src/DataProvider/Doctrine/DoctrineFilterHandler.php); see
[ADR 0069](adr/0069-generalise-the-exists-builder-and-add-wherehasmatching.md) and
[doctrine.md](doctrine.md) for the DQL detail.

## Pivot (`belongsToMany`) data

A `belongsToMany` relation can expose **pivot (join-table) data**: per-member values
that live on the join, like a `position` ordering or an `addedAt` timestamp. Declare
the pivot fields on the relation with `fields()` — as **real field definitions**, the
same field DSL you use for attributes (`Integer`, `Str`, `DateTime`, …) with their
constraints, casts and read-only / context behaviour:

```php
BelongsToMany::make('tracks')->type('tracks')
    ->fields(
        Integer::make('position')->required()->min(1),
        DateTime::make('addedAt')->readOnly(),   // server-owned, never written from meta
        Str::make('note')->maxLength(140),
    );
```

The `fields()` declaration drives pivot **render**, **write / validate** and **sort**.
It renders each member's pivot values as `meta.pivot` on both the related endpoint
(`GET /playlists/1/tracks`) and the relationship-linkage endpoint (`GET
/playlists/1/relationships/tracks`), and makes `?sort=position` recognised on that
related endpoint (routed to the pivot column) — sorting is zero-config:

```jsonc
"data": [
  {
    "type": "tracks", "id": "7",
    "attributes": { "title": "Intro" },
    "meta": { "pivot": { "position": 1, "addedAt": "2024-01-01T00:00:00+00:00" } }
  }
]
```

### Filtering by a pivot column

Pivot **filters are author-declared** — distinct from sorts, which auto-derive. To
expose a `?filter[…]` over a pivot column, declare it on the relation's
`withFilters()` as a **normal core filter whose `column` is `pivot.`-prefixed**. The
`pivot.` prefix marks the filter as targeting the join: the bundle strips the prefix
to the real pivot column (`position`) and routes the filter to the pivot alias. The
filter **key is independent of the column**, so a pivot filter can be named anything:

```php
BelongsToMany::make('tracks')->type('tracks')
    ->fields(
        Integer::make('position')->required()->min(1),
        DateTime::make('addedAt')->readOnly(),
    )
    ->withFilters(
        Where::make('position', 'pivot.position'),           // filter[position]=2
        Where::make('positionGte', 'pivot.position', '>='),  // an operator
        WhereIn::make('positionIn', 'pivot.position'),        // filter[positionIn]=1,3
        Where::make('addedAfter', 'pivot.addedAt', '>'),     // a typed-date column
    );
```

Any core scalar-column filter works on a pivot column: `Where` and its operators,
`WhereIn`/`WhereNotIn`, `WhereNull`/`WhereNotNull`. (`WhereHas`/`WhereDoesntHave` are
relationship-existence filters, not a scalar pivot column, so they are not
pivot-applicable.) The value **cast** auto-resolves from the declared pivot field
backing the stripped column — `Integer` coerces to `int`, `DateTime` to an ISO-8601
comparison — so a typed pivot column filters correctly with no extra wiring; an
explicit `->deserializeUsing()` on the filter still wins. A filter with **no** `pivot.`
prefix targets the related entity, exactly like any relation-scoped filter.

A pivot field declared `hidden()` is **filterable and sortable but never rendered**:
`hidden()` gates rendering only, never query. The field stays out of each member's
`meta.pivot`, yet a `pivot.`-prefixed filter (and `?sort=`) over its column still
works — the filter reads the column on the join directly, not the rendered scalar.
Use it for a join column you want to query by but not expose.

> **Filter / sort asymmetry.** A pivot **sort** auto-derives from `fields()` —
> `?sort=position` works with no further declaration. A pivot **filter** must be
> declared explicitly via `withFilters()` (a sort is a single well-defined ordering; a
> filter spans operators, sets and null checks the author must choose). See
> [ADR 0067](adr/0067-author-declared-pivot-filters-via-a-pivot-column-prefix.md).

> **Migration (breaking, `0.x` minor).** Declaring a pivot field no longer
> auto-exposes a zero-config `filter[<field>]` equality. An app that relied on it must
> add the explicit declaration: `->withFilters(Where::make('position',
> 'pivot.position'))`. Pivot **sorts** are unaffected (still zero-config).

**The Doctrine fact this rests on.** A plain `#[ORM\ManyToMany]` join table holds
only the two foreign keys — Doctrine cannot map a `position`/`addedAt` column on it.
To HAVE pivot columns you must model the join as an **association entity**:

```php
#[ORM\Entity]
class PlaylistTrack
{
    #[ORM\ManyToOne(targetEntity: Playlist::class, inversedBy: 'playlistTracks')]
    public ?Playlist $playlist = null;

    #[ORM\ManyToOne(targetEntity: Track::class)]
    public ?Track $track = null;

    #[ORM\Column(type: 'integer')]
    public int $position = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    public ?\DateTimeImmutable $addedAt = null;
}
```

with the parent owning a `OneToMany` to it (`Playlist -> playlistTracks ->
PlaylistTrack`) and the entity a `ManyToOne` to the far type (`PlaylistTrack -> track
-> Track`). The Doctrine adapter **auto-detects** that association entity from your
metadata (it finds the one to-many on the parent whose target also has a `ManyToOne`
to the far type) and reads pivot render + filter + sort in **one** DQL query over it
— correctly scoped, filtered, sorted and paginated, no extra round-trip. If
auto-detection is ambiguous (two candidate association entities) or finds none, it
throws a clear error pointing you at the override:

```php
BelongsToMany::make('tracks')->type('tracks')
    ->fields(Integer::make('position'))
    ->through(PlaylistTrack::class); // name the association entity explicitly
```

### Writing pivot data

A pivot field is **writable by default** — settable from the linkage's
resource-identifier `meta` (JSON:API allows a resource identifier to carry `meta`).
Opt a server-owned column out with `->readOnly()` (or `->readOnlyOnCreate()` /
`->readOnlyOnUpdate()`); a read-only pivot field is never written from `meta` and
takes its server value. The convention is the same on the relationship endpoints and
inline in a whole-resource write:

```jsonc
// POST /playlists/1/relationships/tracks   — add a member with its pivot data
{ "data": [ { "type": "tracks", "id": "7", "meta": { "position": 3 } } ] }

// PATCH /playlists/1/relationships/tracks  — full replacement = REORDER + sync
{ "data": [
  { "type": "tracks", "id": "9", "meta": { "position": 1 } },
  { "type": "tracks", "id": "7", "meta": { "position": 2 } }
] }

// PATCH /playlists  — the SAME linkage meta inline in a whole-resource write
{ "data": { "type": "playlists", "id": "1", "relationships": {
  "tracks": { "data": [ { "type": "tracks", "id": "7", "meta": { "position": 3 } } ] }
} } }
```

The Doctrine adapter applies an **association-entity diff**: for each incoming member
it upserts the join row — updating an existing row's writable pivot fields *in place*
(the reorder), or creating a new row (the writable fields from `meta`, the read-only
fields taking their server default). A `PATCH` (full replacement) also **removes** the
rows whose member is no longer in the set; a `POST` adds/upserts the incoming and
leaves the rest; a `DELETE` removes the incoming members' rows (a remove carries no
pivot). Each member's `meta` is **validated** against the writable pivot fields'
constraints, with a violation rendered as a `422` pointed at the linkage meta
(`/data/relationships/<rel>/data/<n>/meta/<field>`, or `/data/<n>/meta/<field>` on the
relationship endpoint). Because an add/replace may **create a new association row** for
any incoming member — even on a `PATCH` — the `meta` is validated in the **new-row
(create) context**, matching the persister (a new row is written in create context); a
reorder of an existing row supplies the value, so this never wrongly rejects it. A
**read-only** pivot field supplied in `meta` is **ignored** (it is not in the writable
set, so it never raises and is never written — exactly how a read-only attribute is
handled). A **required** writable pivot field absent when a new row is created is a
`422` (at the linkage meta, before persist — never a database NOT-NULL `500`), on the
relationship-endpoint `POST`/`PATCH` and the whole-resource `POST`/`PATCH` alike.

**Boundaries.**

- **Doctrine-only.** Pivot data requires an association entity to query and write. The
  in-memory provider does not support it: a pivot `?filter`/`?sort` key is
  unrecognised there (`400`), no `meta.pivot` renders, and a pivot-meta **write is
  ignored** (the relation is a plain to-many in-memory — there is no join row to hold
  it). A `belongsToMany` *without* `fields()` (and any `HasMany`) keeps the plain
  related-collection behaviour on both providers.
- **Scoped to the pivot relation's related endpoint.** A pivot key is unrecognised on
  the primary `/{relatedType}` collection (`400`), exactly like a relation-scoped key.
- **One pivot row per member.** `meta.pivot` is a single per-member value set, not a
  list. If the same member appears more than once (duplicate membership — a track at
  two positions), the collection returns one row per distinct member: the total is the
  distinct member count, no member splits across pages, and the rendered pivot values
  are a representative membership row. To render every membership, model the
  membership as its own resource instead.

See [ADR 0045](adr/0045-belongs-to-many-pivot-data-over-an-association-entity.md)
(read) and [ADR 0046](adr/0046-writable-belongs-to-many-pivot-fields.md) (write), and
[doctrine.md](doctrine.md#belongstomany-pivot-data) for the query, resolver and
association-entity-diff detail.

## Relationship mutation

The bundle serves `PATCH`/`POST`/`DELETE …/relationships/{rel}` over the
[`CrudOperationHandler`](../src/Operation/CrudOperationHandler.php). Core owns the
mutation model — request-shape validation (cardinality → 400, mutability flags →
403) and the typed exceptions
([core relationship-mutation](https://github.com/haddowg/json-api/blob/main/docs/relationship-mutation.md)).
The bundle owns the storage-correct apply: it loads the parent, resolves the
named relation, guards mutability, parses the linkage with core's body parser,
then calls the persister's `DataPersister::mutateRelationship()` seam (the
six-arg signature lives in [data-layer.md](data-layer.md)).

The persister resolves the linkage's identifier ids to the actual related
objects/references — the Doctrine reference to a managed reference + FK write, the
in-memory provider to the stored object via its `$relatedResolver`. An empty
linkage clears the relationship. The **same seam** is reused for relationships
embedded in whole-resource writes (a `data.relationships` member on a
`POST`/`PATCH /{type}` — applied with `flush: false` so the single
`create()`/`update()` owns the commit).

The [`RelationshipMutationTest`](../examples/music-catalog-symfony/tests/RelationshipMutationTest.php)
exercises the full matrix over Doctrine, re-reading after clearing the identity
map so the assertion proves the change reached the database:

```
PATCH /tracks/1/relationships/album   {"data":{"type":"albums","id":"2"}}  → 200, replaced
PATCH /tracks/1/relationships/album   {"data":null}                        → 200, cleared
POST  /tracks/3/relationships/playlists {"data":[{…}]}                     → 200, added (idempotent)
DELETE /tracks/1/relationships/playlists {"data":[{…}]}                    → 200, removed
```

A whole-resource write threads the same path — a `favorites` create carrying a
to-one `user` in `data.relationships` writes the FK, and a `tracks` PATCH
carrying `album` replaces the association.

> Note: a `data.relationships` member on a create/update is **not** hydrated by
> core onto a typed association property — the bundle strips it before core
> hydrates id+attributes, then applies each relationship through
> `mutateRelationship(... Mode::Replace, flush: false)`. See [data-layer.md](data-layer.md).

## Per-relation endpoint exposure

A relation can suppress individual endpoints. The exposure flags themselves are
**core** (`RelationInterface`), but the bundle enforces them **handler-side** —
the relationship routes stay parametric (one route per shape, emitted once per
type), so suppression cannot live in routing. The handler maps each flag to a
JSON:API error, and core already omits the convention link to a suppressed
endpoint, so a rendered `self`/`related` link never points at a 404 (ADR 0027):

| Flag (core, on the relation) | Request affected | Bundle result |
|------------------------------|------------------|---------------|
| `withoutRelatedEndpoint()` | `GET /{type}/{id}/{rel}` | `404` `RELATIONSHIP_NOT_EXISTS` |
| `withoutRelationshipEndpoint()` | `GET`/mutate `…/relationships/{rel}` | `404` `RELATIONSHIP_NOT_EXISTS` |
| `cannotAdd()` | `POST …/relationships/{rel}` | `403` `ADDITION_PROHIBITED` |
| `cannotReplace()` | `PATCH …/relationships/{rel}` | `403` `FULL_REPLACEMENT_PROHIBITED` |
| `cannotRemove()` | `DELETE` / to-one clear | `403` `REMOVAL_PROHIBITED` |

The enforcement is in
[`CrudOperationHandler::guardMutability()`](../src/Operation/CrudOperationHandler.php).
[`TrackResource`](../examples/music-catalog-symfony/src/Resource/TrackResource.php)'s
`playlists` declares `cannotReplace()`, so a full `PATCH` replacement of that
to-many is a `403` and the existing set is untouched — witnessed by
`RelationshipMutationTest::patchingACannotReplaceToManyIsForbidden`. A cardinality
mismatch (a `POST`/`DELETE` against a to-one) is a separate `400`
`RELATIONSHIP_TYPE_INAPPROPRIATE`; an unknown relation or missing parent is a
`404`.

## Controlling what can be included

`?include` is a compound-document amplifier — a deeply nested path or a
default-include cascade can walk the relationship graph without bound, and a
default-include pointing back at its own type (or a mutual pair) loops the renderer
forever. Three composing **include safeguards** (bundle ADR 0037) bound it. All three
live in **core** (an opt-in `IncludeControlsInterface` the transformer reads via
`instanceof`, plus a relation-level wither); the bundle supplies the opinionated depth
default and makes the built-in include batcher respect them.

| Safeguard | Where declared | Effect |
|-----------|----------------|--------|
| **A — per-relation opt-out** | `cannotBeIncluded()` on the relation | A `?include` naming it is a `400` `INCLUSION_NOT_ALLOWED` at any path, and it is excluded from the default-include cascade. |
| **B — max include depth** | `json_api.max_include_depth` (default `3`), overridable per resource via `IncludeControlsInterface::maxIncludeDepth()` | A `?include` deeper than the effective cap is a `400` `INCLUSION_DEPTH_EXCEEDED`; a default cascade stops at the cap (so a mutual default-include cycle terminates). |
| **C — allowed include paths** | `IncludeControlsInterface::getAllowedIncludePaths()` on the root resource | A list of the full dotted paths a client may request when **this** resource is the request's root (a listed deep path implies its ancestors); any requested path neither listed nor an ancestor of one is a `400` `INCLUSION_NOT_ALLOWED`. `null` is unrestricted; `[]` permits no includes. |

They **compose**: a requested path is permitted only if every hop's relation is
includable (A), it is within the effective max depth (B), and — when the root sets a
whitelist — it is a member of that list (C).

A relation marks itself non-includable like any other flag:

```php
// A back-reference whose only purpose is reverse navigation, not compounding:
BelongsTo::make('parent')->type('categories')->cannotBeIncluded();
```

Capability A is **all-or-nothing for a relation at its own level** — it cannot say
"`comments` is includable on `posts` directly (`GET /posts?include=comments`) but NOT
when reached from a parent (`GET /users?include=posts.comments`)". Capability C closes
exactly that gap: it is evaluated **once against the request's root resource** and
governs the whole nested tree, so it can forbid a nested path even where the relation is
includable from its own root:

```php
final class UserResource extends AbstractResource
{
    // Reading a user may compound their posts and each post's author, but never a
    // post's comments — even though `comments` is freely includable on `posts`.
    public function getAllowedIncludePaths(): ?array
    {
        return ['posts.author'];
    }
}
```

A listed deep path **implies its ancestors**, so `['posts.author']` permits both
`posts` and `posts.author` without enumerating every prefix; only the unlisted
sibling `posts.comments` is rejected.

`max_include_depth` is the one config-driven safeguard — see
[configuration → `max_include_depth`](configuration.md#max_include_depth) for the cap,
the per-resource override, and how it terminates a default-include cycle. The built-in
include batcher (no extra dependency; see
[doctrine → eager-loading includes](doctrine.md#eager-loading-includes-no-n1)) honours
all three: it never batch-loads a non-includable relation, bounds its own recursion by
the same effective depth, and skips a path the root's whitelist excludes (so a mutual
default-include cycle terminates the batcher too, not only the renderer).

## Polymorphic relationships

A `MorphTo` to-one and a `MorphToMany` to-many point across several related
types, so the member's serializer is resolved per-object rather than from a
single declared type. Core's `PolymorphicSerializer` and per-object resolution
own the rendering
([core related-endpoints](https://github.com/haddowg/json-api/blob/main/docs/related-endpoints.md));
the bundle wires the data path and splits responsibility between the two
providers (ADR 0032).

### Polymorphic to-one (`MorphTo`)

[`FavoriteResource`](../examples/music-catalog-symfony/src/Resource/FavoriteResource.php)
declares `favoritable` over three types:

```php
MorphTo::make('favoritable')
    ->types('tracks', 'albums', 'artists')
    ->extractUsing(static fn(mixed $favorite): ?object => $favorite instanceof Favorite ? $favorite->favoritable : null),
```

The handler resolves the to-one serializer from the **actual** related object, so
the same relation renders an `albums` resource for one favorite and an `artists`
resource for another, and an empty target renders `data: null`. Because the
target spans entity classes, the Doctrine read leaves the non-mapped property
null; a thin custom provider —
[`FavoriteProvider`](../examples/music-catalog-symfony/src/Provider/FavoriteProvider.php)
— delegates the fetch to the Doctrine provider, then fills `$favoritable` from the
entity's stored `targetType`/`targetId` pair across per-type repositories (sharing
the EntityManager, so the member comes back managed). The
[`PolymorphicTest`](../examples/music-catalog-symfony/tests/PolymorphicTest.php)
witnesses `GET /favorites/1/favoritable` → a `tracks` member, `/favorites/2/…` →
an `albums` member, `/favorites/3/…` → an `artists` member.

### Polymorphic to-many (`MorphToMany`)

[`LibraryResource`](../examples/music-catalog-symfony/src/Resource/LibraryResource.php)
declares `MorphToMany::make('items')->types('tracks', 'albums', 'artists')` — a
mixed collection. The provider responsibilities differ by storage:

- **The in-memory provider supports it**: it reads the mixed collection off the
  parent and slices with `page`. A polymorphic to-many carries no shared
  filter/sort vocabulary, so `filter`/`sort` are a `400` while `page` windows.
- **The Doctrine provider throws** "unsupported" for a polymorphic to-many —
  members span entity classes, so no single scoped query can fetch them. You
  supply a custom provider (→ [custom-data-providers.md](custom-data-providers.md)).

The example app demonstrates the escape hatch with
[`LibraryItemsProvider`](../examples/music-catalog-symfony/src/Provider/LibraryItemsProvider.php),
which resolves the mixed `libraries.items` members across their per-type
repositories. It registers for **two** types so every entry point sees the same
members: for `libraries` it delegates the parent fetch to Doctrine then populates
the non-mapped `Library::$items` (so the linkage endpoint and `?include` read the
mixed list off the parent), and for `tracks` it answers `fetchRelatedCollection()`
(the related-collection dispatch resolves a provider by the relation's first
declared type). The members render through a `PolymorphicSerializer` that
discriminates each by its own type. `PolymorphicTest` witnesses
`GET /libraries/1/items` → `[tracks:1, albums:2, artists:1]`, the matching
linkage endpoint, and `?include=items` yielding the three mixed `included`
resources.

## Relations without a resource

Relations are a standalone capability: you can declare a type's relations with no
`AbstractResource` (ADR 0026). Put `#[AsJsonApiRelations(type: …)]` on a class
implementing [`RelationsProviderInterface`](../src/Server/RelationsProviderInterface.php) —
its `relations(): array` returns the type's `RelationInterface` list:

```php
#[AsJsonApiRelations(type: 'libraries')]
final class LibraryRelations implements RelationsProviderInterface
{
    public function relations(): array
    {
        return [
            BelongsTo::make('owner')->type('users'),
            MorphToMany::make('items')->types('tracks', 'albums', 'artists'),
        ];
    }
}
```

Autoconfiguration tags it `haddowg.json_api.relations` (`RELATIONS_TAG`); the
bundle holds it in a lazy, type-keyed `RelationsRegistry` (type-keyed, not
class-string-keyed, because relations are runtime objects core cannot read
statically). The route loader gates the relationship routes on a type *having
relations* — from a resource **or** a standalone provider — and
`TypeMetadataResolver` sources relations resource-first then from the registry, so
a resource-less type (paired with [`#[AsJsonApiSerializer]`](capability-composition.md))
gets identical relationship routes, rendering, and whole-resource relationship
writes. See [capability-composition.md](capability-composition.md) for the wiring
rationale.

The `server` argument on `#[AsJsonApiRelations]` assigns the type to the named
server(s), the same as the resource attribute (→ [multi-server-and-testing.md](multi-server-and-testing.md)).

> The example app declares all relations on resources, so the standalone form is
> shown here in prose; the bundle's `RelationsRegistry`/`TypeMetadataResolver`
> path is the same one resources take.

## Next / see also

- [routing.md](routing.md) — the relationship/related route names and the
  segment-count ordering that keeps `relationships` from being captured as a
  `{relationship}` name.
- [data-layer.md](data-layer.md) — the `mutateRelationship()` and
  `fetchRelatedCollection()` SPI signatures and the whole-resource
  relationship-strip flow.
- [doctrine.md](doctrine.md) — the FK-vs-`IN`-subquery related-collection scoping
  and the load-state seam.
- [custom-data-providers.md](custom-data-providers.md) — supplying a provider for
  a polymorphic to-many or any related collection the Doctrine reference cannot
  scope.
- Core: [relations](https://github.com/haddowg/json-api/blob/main/docs/relations.md),
  [related-endpoints](https://github.com/haddowg/json-api/blob/main/docs/related-endpoints.md),
  [relationship-mutation](https://github.com/haddowg/json-api/blob/main/docs/relationship-mutation.md).
