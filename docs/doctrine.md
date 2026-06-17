# The Doctrine reference data layer

The bundle ships a reference data layer that turns any Doctrine-ORM-mapped entity
into a fully queryable JSON:API type with **no per-type code**. Map an entity on a
resource and the bundle's Doctrine [`DataProvider`](data-layer.md) and
[`DataPersister`](data-layer.md) serve every read and write endpoint over it —
translating `?filter`/`?sort`/`?page` into DQL, scoping related collections, and
committing hydrated entities through one `EntityManager`.

This page documents the Doctrine reference implementation: the entity-map
compiler pass, the read and write pipelines, the DQL filter/sort translation, the
related-collection scoping, constructor-less instantiation, and the load-state
seam. The storage-agnostic SPI these classes implement is on
[data-layer.md](data-layer.md); overriding or scoping the Doctrine layer is on
[custom-data-providers.md](custom-data-providers.md).

The filter and sort *vocabulary* these handlers execute is core's — link
[filters](https://github.com/haddowg/json-api/blob/main/docs/filters.md) and
[sorts](https://github.com/haddowg/json-api/blob/main/docs/sorts.md) for the value
objects, [pagination](https://github.com/haddowg/json-api/blob/main/docs/pagination.md)
for `OffsetWindow`, and
[adapters](https://github.com/haddowg/json-api/blob/main/docs/adapters.md) for the
`FilterHandlerInterface`/`SortHandlerInterface` these Doctrine handlers implement.

## Activation: the entity map

The Doctrine layer is active only when **both** of these hold:

1. `doctrine/orm` is installed (it is a `suggest` + `require-dev` dependency, not a
   hard one — see the optional-dependency matrix on
   [configuration.md](configuration.md)), and
2. at least one resource maps an entity via `#[AsJsonApiResource(entity: …)]`.

You map an entity on the resource attribute:

```php
#[AsJsonApiResource(entity: Album::class, server: ['default', 'admin'])]
final class AlbumResource extends AbstractResource
{
    public static string $type = 'albums';
    // …
}
```

— from [`AlbumResource`](../examples/music-catalog-symfony/src/Resource/AlbumResource.php),
backed by the [`Album`](../examples/music-catalog-symfony/src/Entity/Album.php)
entity. The attribute and its arguments are covered on [resources.md](resources.md).

At container-build time `DoctrineEntityMapPass` collects every
`#[AsJsonApiResource(entity: …)]` declaration and builds a `type → entity-class`
map, keyed by the attribute's `type` override or the resource class's static
`$type` (the same precedence the runtime registry uses). That map is injected into
the Doctrine provider, the Doctrine persister, and (with no map argument) the
load-state predicate.

When the map is **empty** — Doctrine is in the vendor tree but no resource maps an
entity — the pass *removes* the Doctrine provider, persister, and load-state
definitions entirely, so a non-Doctrine-integrated app never holds a definition
referencing an absent `EntityManagerInterface`.

### Build-time faults

These are all `\LogicException` at container build, never request-time errors:

| Fault | Message gist |
|---|---|
| `entity:` names a class that does not exist | *The entity class "…" mapped by #[AsJsonApiResource] on service "…" does not exist.* |
| Type cannot be determined (no static `$type`, no `type:` override) | *Cannot determine the JSON:API type for the entity mapping on service "…".* |
| Two resources map one type to different entities | *JSON:API type "…" is mapped to two different Doctrine entities: "…" and "…".* |

Source: [`DoctrineEntityMapPass`](../src/DependencyInjection/Compiler/DoctrineEntityMapPass.php).

## The read pipeline

`DoctrineDataProvider` answers `fetchOne` and `fetchCollection` over the
`EntityManager`.

A **collection fetch** is one `QueryBuilder` pipeline:

1. every supporting [query extension](custom-data-providers.md) customizes the
   builder first (base scopes the client cannot undo, eager-load joins);
2. the shared `CriteriaApplier` ([data-layer.md](data-layer.md)) matches the
   requested `filter[…]`/`sort` against the declared vocabularies and pushes each
   down through `DoctrineFilterHandler`/`DoctrineSortHandler`;
3. for a windowed fetch, a `COUNT` runs over the filtered (un-ordered,
   un-windowed) query *before* the window applies as `LIMIT`/`OFFSET` — so items
   are never over-fetched and the reported total agrees with the applied scope.

A **single fetch** (`fetchOne`) runs the same extension pipeline — so a base scope
holds for `GET /{type}/{id}` too — and falls back to `EntityManager::find()` (and
its identity-map fast path) only when **no** extension supports the type. A row a
scope excludes comes back as `null`, which the handler renders as a JSON:API
`404`.

Only an `OffsetWindow` is executable: any other `WindowInterface` throws a
`\LogicException` (the Doctrine layer pushes offset/limit to SQL; it cannot
execute a cursor window). The page-based paginators core ships all resolve to an
`OffsetWindow`.

Source: [`DoctrineDataProvider`](../src/DataProvider/Doctrine/DoctrineDataProvider.php).

## Encoded resource ids (storage key != wire id)

A resource can decouple the JSON:API `id` a client sees (the **wire** id) from the
**storage** key its entity is actually keyed by — exactly Laravel JSON:API's custom
id encoding. Attach an encoder to the `Id` field with core's
`Id::encodeUsing(IdEncoderInterface)`:

```php
public function fields(): array
{
    return [
        Id::make()
            ->encodeUsing(new ProductIdCodec())   // storage key <-> wire id
            ->matchAs('prod-[0-9a-f]+'),           // the route {id} requirement
        Str::make('name')->required(),
    ];
}
```

```php
interface IdEncoderInterface
{
    public function encode(mixed $storageKey): string; // storage -> wire
    public function decode(string $wireId): mixed;      // wire -> storage; null when undecodable
}
```

The entity **always holds the storage key**. The transform runs at two boundaries:

- **Core** owns the entity's-own-id transform: it `encode()`s the stored key on
  serialize (so the rendered `id` and every self/related link are wire ids) and
  `decode()`s a **client-supplied** id on create, setting the **storage key** on the
  new entity (a `null` decode is a `422`). A *server*-generated id (no client id, the
  default) is the storage key's own wire form and is set **as-is** — it is never fed
  to `decode()`, so a server-minted create is not spuriously rejected. A `PATCH` does
  not set the id, so it needs no decode either. (A type whose id is database-generated
  has no id to hydrate on create at all; expose it without `Create` rather than mint a
  meaningless id — see the example's `ProductResource`.)
- The **reference Doctrine layer** owns the id-as-lookup-key transforms, because the
  storage-agnostic [`DataProvider`/`DataPersister` SPI](data-layer.md) passes ids as
  **wire** strings and keeps its signatures unchanged. Before the lookup the Doctrine
  provider `decode()`s the route `{id}`; a `null` decode short-circuits to a `404`
  (no row holds that key, so no query runs). Before `getReference()` the Doctrine
  persister `decode()`s each linkage id (keyed by the *related* type's encoder), so a
  relationship write whose `data` carries wire ids resolves the right managed
  references; a linkage id that `decode()`s to `null` is a bad target and raises a
  `404` (rather than passing the raw wire string to `getReference`, which would build
  a proxy that errors on initialization — a `500`).

So a read round-trips `wire -> (Doctrine decode) -> storageKey -> query -> entity ->
(core encode) -> wire`, and a create-with-client-id round-trips `wire -> (core decode)
-> entity (storageKey) -> persist -> (core encode) -> wire`.

The `uuid()` / `ulid()` / `numeric()` / `pattern()` shortcuts set the route `{id}`
requirement **and** the client-id format constraint; `matchAs()` sets the route
requirement alone (the inner regex, no surrounding `^…$` — Symfony anchors it). The
route loader stamps that requirement on every route carrying `{id}`, so a **malformed
id `404`s at routing** before any handler runs. A type with **no** encoder is
unchanged (wire == storage), and the **in-memory** provider has no encoder at all, so
encoding is a Doctrine-only concern — encoders are entirely user-supplied (no encoder
dependency is added to the bundle). See [ADR 0038](adr/0038-doctrine-layer-decodes-encoded-resource-ids.md).

## Eager-loading includes (no N+1)

Eager-loading of a read's `?include` tree is **automatic and built in** — there is
**no extra dependency to install** (it used to require an external preloader
library; that is gone, and the batching now lives in the bundle). Includes stop
N+1ing with no per-type code. The bundle batch-loads the included relationships
Laravel-style: **one query per relation per level**, no fetch-joins. Each level
loads a relation for *every* source entity in a single `WHERE id IN (…)`-style
query, and the loaded targets seed the next level.

Over the example, `GET /albums?include=tracks` across 16 albums issues 2
include-load queries (the albums, then one batched tracks load) — not the `1 + N` a
lazy render issues:

```http
GET /albums?include=tracks&page[size]=100
```

The batcher reuses **core's** include decision, so it loads exactly what is
rendered. This includes **default includes**: a resource's
`getDefaultIncludedRelationships()` is applied by core as a *fallback* — when the
request sends no `?include`, the listed relationships are included (and now
batch-loaded); an explicit `?include` (even an empty `?include=`) overrides the default.

```php
final class AlbumResource extends AbstractResource
{
    // GET /albums with no ?include yields each album's artist in `included`
    // (rendered AND batch-loaded); ?include=… or ?include= overrides it.
    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return ['artist'];
    }
}
```

— from [`AlbumResource`](../examples/music-catalog-symfony/src/Resource/AlbumResource.php).

Batch-loading is a **pure optimization**: the rendered document is identical with or
without it. So a relation the batcher cannot batch silently falls back to a lazy
load — a polymorphic relation (more than one related type), a computed /
`extractUsing` / aliased non-association column, or a composite-key target. The
relation's storage column drives the batch (`column() ?? name()`), so a `storedAs()`
rename is honoured.

The batching runs through the same provider-agnostic
[`fetchRelatedCollectionBatch()`](../src/DataProvider/DataProviderInterface.php) seam
that windowed related collections use, so it works on the in-memory provider too (an idempotent
re-assignment that changes no rendered bytes). See
[ADR 0062](adr/0062-load-plain-includes-through-the-batched-related-fetch.md) (which
folded plain-include loading onto the batched related-fetch and removed the external
preloader) and ADRs [0035](adr/0035-doctrine-include-batch-preloading.md) /
[0061](adr/0061-batch-windowed-related-collections-in-one-query-per-relation.md); the
witness is [`IncludePreloadTest`](../examples/music-catalog-symfony/tests/IncludePreloadTest.php).

### Windowed includes (`window_functions`)

A *plain* include loads the whole related set (the fast-path above). Under the
[Relationship Queries profile](relationships.md) a request can instead **window** each
parent's included to-many relation to page 1 (e.g. the 5 newest comments per post). The
provider runs that as ONE bounded native `ROW_NUMBER() OVER (PARTITION BY parent
ORDER BY …)` query per relation — fetching only ~one page **per parent** plus the
**real** per-parent total (`COUNT(*) OVER`), never the parent's whole set (bundle ADR
0065). The result is bounded even though the engine scans the partition, and the total
is the true cardinality — so the relationship-pagination total (and any `?withCount`
overlap) is correct, not the page size.

> [!IMPORTANT]
> `json_api.doctrine.window_functions` defaults to `true` and needs SQL window
> functions: **MySQL ≥ 8, MariaDB ≥ 10.2, SQLite ≥ 3.25, or any PostgreSQL**. On an
> older engine the first windowed include throws a `500` (logged, naming these floors).
> Set it `false` to use the per-parent bounded fallback — one real `LIMIT`/`OFFSET`
> query per parent (no window function), rendering byte-identical documents:
>
> ```yaml
> # config/packages/json_api.yaml
> json_api:
>     doctrine:
>         window_functions: false
> ```
>
> There is no auto-detection (no probe/cache/fallback-on-error); the switch is explicit.

Two native shapes mirror the [related-collection scoping](#related-collection-scoping):
an **inverse-FK** `OneToMany` partitions by the related table's parent FK and hydrates
the entity inline (one statement); an **owning-side / many-to-many** relation joins the
join table, partitions by its parent column, and id-loads the distinct related entities
(two statements — the ORM object hydrator would otherwise dedup a member shared across
parents). The ORDER BY appends a PK tiebreak (matched in the in-memory witness) so ties
resolve identically on both providers.

A **filtered** windowed include (`relatedQuery[<rel>][filter][…]`) runs as ONE bounded
native query too: the inner scoped query carries the relatedQuery filter through the same
DQL filter executor the related-collection endpoint runs, then is wrapped with the window
functions — so a filtered windowed include is also one bounded query on `on`, with the
**filtered** per-parent total (bundle ADR 0066). Only a related type with a **query
extension** (or `window_functions: false`) takes the per-parent bounded fallback. See
[pagination → windowed includes](pagination.md#windowed-includes-are-bounded-window_functions),
[ADR 0065](adr/0065-bound-windowed-includes-with-a-row-number-batch-query.md), and
[ADR 0066](adr/0066-fold-filtered-windowed-includes-onto-the-native-batch.md).

## The write pipeline

`DoctrineDataPersister` is the write twin of the provider, committing through the
**same** `EntityManager` the provider reads with.

- **`create()`** is `persist()` + `flush()`. It makes **no assumption that the id is
  pre-set**: with the store-provided default (a `#[ORM\GeneratedValue]` column), the
  bundle's hydrator sets nothing on the id and Doctrine assigns it on flush — the
  handler then reads it back (via the serializer's id accessor) for the `201` body
  and `Location`. So a plain `Id::make()` over an auto-increment entity round-trips
  the database-assigned id with no persister change (see
  [resources § Sourcing the resource id](resources.md#sourcing-the-resource-id)).
- **`update()`** relies on the target being a *managed* instance the hydrator
  mutated in place — the provider loaded it through this same `EntityManager`, so
  `update()` is just `flush()`. There is no `persist`/`merge`.
- **`delete()`** is `remove()` + `flush()`.

The managed-update coupling is the one constraint a custom data layer must respect:
a provider that returns a **detached** entity from `fetchOne` would silently break
Doctrine updates, because there would be nothing managed to flush. Provider and
persister must share the `EntityManager` — the reference pair does. If you replace
*one* of them for a type, replace both (see
[custom-data-providers.md](custom-data-providers.md)).

Source: [`DoctrineDataPersister`](../src/DataPersister/Doctrine/DoctrineDataPersister.php).

### Relationship write query cost

When a write carries a `data.relationships` member, the persister resolves each
linkage id with `EntityManager::getReference()` — a lazy proxy, **no query** — so how
the linkage costs depends on the relation's *owning side*:

- **A many-to-many the parent owns** (the join-table side, e.g. `editors`) stays
  **O(1)**: the proxy is added to the owning collection and the join row inserts from
  its known id, never loading the related entity. Creating or replacing such a
  relation with 2 ids or 200 issues the same number of `SELECT`s (none for the
  linkage).
- **An inverse one-to-many** (the foreign key lives on the *child*, e.g. `comments`
  where `comment.article_id` is the FK) costs **one `SELECT` per incoming id**:
  re-pointing a child means setting its owning-side association
  (`$comment->article = $article`), and setting a field on a `getReference()` proxy
  initialises it. So a create / replace / add / remove of an inverse one-to-many is
  O(linkage size).

This is an **accepted limitation** (bundle ADR 0072), not a bug: re-pointing N managed
children through the ORM inherently needs them managed (the unit of work tracks each
FK change), and the only O(1) alternative — a bulk `UPDATE … WHERE id IN (...)` —
bypasses the unit of work and the children's lifecycle/cascade events. It only matters
for *large* to-many re-points; a handful of ids is negligible. If you need O(1) bulk
re-pointing, supply a [custom `DataPersister`](custom-data-providers.md) that issues
the bulk update. `DoctrineWriteQueryBudgetTest` pins both behaviours.

### Constructor-less instantiation

On create, the persister builds a blank instance via
`ClassMetadata::newInstance()` — the same constructor-less mechanism the ORM uses
to hydrate entities on read (ADR 0029). So entities with **required constructor
arguments** work under the generic engine without a custom persister:

```php
public function __construct(
    #[ORM\Id]
    #[ORM\Column]
    public string $id = '',
    #[ORM\Column]
    public string $title = '',
    // …
) {
    $this->tracks = new ArrayCollection();
}
```

The trade-off: because the constructor is **not** invoked, its
invariants/defaults do not run on create — consistent with read-hydration, where
they also don't. (Note that the `Album` constructor above initialises
`$this->tracks`; with the constructor skipped, that association collection is left
uninitialised. The persister re-initialises an uninitialised to-many collection
property to an empty `ArrayCollection` before applying embedded relationships, so a
whole-resource create that sets a to-many does not hit an "accessed before
initialization" `\Error`.) An app that needs the constructor to run overrides
`instantiate()` via a custom persister.

See [ADR 0029](adr/0029-doctrine-constructor-less-instantiation.md).

## Filter translation to DQL

`DoctrineFilterHandler` executes core's filter value objects against the
`QueryBuilder`, pushing each predicate down as a parameter-bound `andWhere`. The
semantics mirror core's in-memory `ArrayFilterHandler` (the conformance witness),
so the same spec test passes on both providers.

| Core filter VO | DQL translation |
|---|---|
| `Where` (`=`/`==`/`===`) | `= :param` (DQL has one type-coercing equality) |
| `Where` (`!=`/`<>`) | `<> :param` |
| `Where` (`>`/`>=`/`<`/`<=`) | the same operator, `:param`-bound |
| `Where` (`like`) | `LOWER(col) LIKE :param ESCAPE '!'` — contains-match, ASCII case-insensitive |
| `WhereIn` / `WhereIdIn` | `col IN (:list)` |
| `WhereNotIn` / `WhereIdNotIn` | `col NOT IN (:list)` |
| `WhereNull` / `WhereNotNull` | `col IS NULL` / `col IS NOT NULL` (request value ignored) |
| `WhereHas` / `WhereDoesntHave` | correlated `EXISTS` / `NOT EXISTS` subquery |
| `WhereThrough` | dotted-traversal correlated `EXISTS` (the related entity narrowed by the leaf comparison) |
| `WhereHasMatching` | correlated `EXISTS` whose related entity is narrowed by an author-supplied `Criteria`/closure (Doctrine-only) |
| anything else | core `UnsupportedFilter` |

A few translations carry nuance:

- **`like` is contains, ASCII-case-insensitive.** The value's `%`/`_` are escaped
  as literals (with `!`), wrapped in `%…%`, and both sides are `LOWER()`ed so the
  result does not depend on the platform's `LIKE` collation (PostgreSQL's `LIKE`
  is case-sensitive; SQLite folds ASCII only). Case-folding beyond ASCII remains
  platform-defined. A non-string filter value matches nothing (`stripos` requires
  two strings).
- **Empty-list semantics.** `WhereIn`/`WhereIdIn` with an empty list match nothing
  (`IN ()` is not valid SQL, so the handler emits `1 = 0`); the negated variants
  then match everything (a no-op).
- **`WhereHas`/`WhereThrough`/`WhereHasMatching` share one `EXISTS` builder.** All
  three relationship filters push down through one correlated `EXISTS` (`NOT EXISTS`
  for `WhereDoesntHave`) subquery rooted on the **related** entity (the first hop's
  target) and correlated back to the outer owner by a membership `IN`-subquery on the
  owning association (uniform for to-one and to-many, owning-side and many-to-many).
  This is set-membership, not a join into the primary `SELECT`, so primary rows are
  neither duplicated nor in need of `DISTINCT`, it never hydrates the relation
  (linkage / `?include` / the relationQuery profile compose for free), and a to-one
  and a to-many translate identically. The three front-ends differ only in what they
  ask of that builder: `WhereHas`/`WhereDoesntHave` are the degenerate
  pure-existence path (no leaf predicate); `WhereThrough` chains the path's
  intermediate segments as joins off the related root and compares the final segment
  as the leaf; `WhereHasMatching` narrows the related root with an author-supplied
  predicate. See the [relationship-existence filtering](#relationship-existence-filtering-wherehas-wherethrough-wherehasmatching)
  subsection below.

The example `AlbumResource` declares two of them:

```php
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereThrough;

public function filters(): array
{
    return [
        // filter[tracks]=1 — albums with at least one related track.
        WhereHas::make('tracks'),
        // filter[artist.name]=Radiohead — EXISTS-ANY over the album's artist.
        WhereThrough::make('artist.name'),
    ];
}
```

— from [`AlbumResource`](../examples/music-catalog-symfony/src/Resource/AlbumResource.php).
`GET /albums?filter[tracks]=1` keeps albums with at least one related track, and
it ANDs *on top of* the published base scope — an app-supplied query extension,
the example's [`PublishedAlbumsExtension`](custom-data-providers.md#doctrineextensioninterface--scoping-the-doctrine-queries);
the [`DoctrineExtensionTest`](../examples/music-catalog-symfony/tests/DoctrineExtensionTest.php)
asserts exactly that composition.

Source: [`DoctrineFilterHandler`](../src/DataProvider/Doctrine/DoctrineFilterHandler.php).

### Relationship-existence filtering: `WhereHas`, `WhereThrough`, `WhereHasMatching`

Three filters keep a row by what its *relationships* contain, never by a column on
the row itself. All three execute as the single correlated `EXISTS` subquery
described above (ADR 0069 generalised the one builder), so they share its
properties: no fetch-join, no row multiplication, and free composition with linkage
/ `?include` / the relationQuery profile.

- **`WhereHas` / `WhereDoesntHave`** — pure existence. They ignore the request value
  and match rows whose named association has (`WhereHas`) or lacks
  (`WhereDoesntHave`) at least one related row.

- **`WhereThrough`** — **dotted-path traversal**, the constrained-existence filter
  (core ADR 0063, bundle ADR 0069). `WhereThrough::make('artist.name')` responds to
  `filter[artist.name]` and keeps a row whose `artist`'s `name` matches — an
  **EXISTS-ANY** semi-join. Every intermediate segment is a relationship (to-one or
  to-many, both translate identically as "there exists a … whose …") and the final
  segment is the compared attribute, so the path chains:
  `filter[author.company.name]`. The wire key carries the dots by default; supply an
  explicit key with the two-argument form
  (`WhereThrough::make('topArtist', 'artist.name')` → `filter[topArtist]`). The leaf
  comparison shares `Where`'s operator vocabulary (`=`, `!=`, `>`, `like`, …) via the
  fluent `operator()` setter (default `=`), and it is **value-validated** (the
  `numeric()`/`integer()`/`pattern()`/`constrain()` shortcuts, see
  [data-layer → validating filter values](data-layer.md#validating-filter-values))
  and **portable**: core ships the metadata + an in-memory traversal, so the same
  `filter[artist.name]` runs on **both** providers (the in-memory provider walks the
  object graph; the Doctrine reference renders the correlated `EXISTS` rooted on the
  related `Artist`). The example's `AlbumResource` declares it — `GET
  /albums?filter[artist.name]=Radiohead` keeps Radiohead's albums.

- **`WhereHasMatching`** — the **Doctrine-only escape hatch** for what `WhereThrough`'s
  single dotted comparison cannot express: a multi-column / OR / NOT predicate, or raw
  DQL. It lives in the bundle's
  [`haddowg\JsonApiBundle\DataProvider\Doctrine\Filter`](../src/DataProvider/Doctrine/Filter/WhereHasMatching.php)
  namespace (not core), with two construction surfaces:

  ```php
  use Doctrine\Common\Collections\Criteria;
  use haddowg\JsonApiBundle\DataProvider\Doctrine\Filter\WhereHasMatching;

  public function filters(): array
  {
      return [
          // A Doctrine Criteria applied (AND/OR/NOT) on the related root — structured and safe.
          WhereHasMatching::criteria('hot', 'tracks', Criteria::create()->where(
              Criteria::expr()->gt('playCount', 1000),
          )),
          // A raw-subquery closure parameterised by the request value — the deep hatch.
          WhereHasMatching::using('named', 'tracks', static function (
              QueryBuilder $sub,
              string $relatedAlias,
              mixed $value,
          ): void {
              $sub->andWhere(\sprintf('%s.title LIKE :q', $relatedAlias))
                  ->setParameter('q', '%' . $value . '%');
          }),
      ];
  }
  ```

  Two boundaries follow from it being Doctrine-only: it is **not portable** — the same
  `filter[hot]` key is undeclared on the in-memory provider, so a request there is a
  clean `400` (the unrecognised-filter boundary, exactly like a pivot key), never a
  silent non-match — and it is **not value-validated** (the author owns the value: it
  is consumed by the closure, not compared by a declared operator, so `constraints()`
  returns `[]`). Reach for it only when `WhereThrough` cannot express the predicate.

See [ADR 0069](adr/0069-generalise-the-exists-builder-and-add-wherehasmatching.md) for
the full decision and [relationships.md](relationships.md) for relationship-endpoint
context.

## Sort translation to DQL

`DoctrineSortHandler` translates **only** `SortByField`. Directives arrive most
significant first (one composite call) and append as sequential `addOrderBy` terms,
so the request's first `sort` field is the primary key, as the spec requires. The
`-` descending prefix is resolved by the shared `CriteriaApplier` and arrives as a
per-directive `descending` flag.

Anything computed or multi-column has no generic DQL translation and raises core's
`UnsupportedSort` — a resource that needs one declares its own handler or a custom
provider.

Source: [`DoctrineSortHandler`](../src/DataProvider/Doctrine/DoctrineSortHandler.php).

## Column safety

Filter and sort columns come from the **server-side resource declaration**, never
from the client (the client supplies only the declared filter key / sort field
name). Before interpolation, each column is regex-validated as a DQL field path —
`^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$`, dots allowed for embedded
fields — so a declaration typo fails loudly rather than reaching the DQL parser
interpolated. Values are *always* bound as query parameters, and every generated
placeholder/scope parameter is prefixed `jsonapi_` (collision-free; reserved — a
[query extension](custom-data-providers.md) must avoid that prefix).

## Related-collection scoping

For a related to-many endpoint (`GET /{type}/{id}/{rel}`), the provider's
`fetchRelatedCollection` scopes the **related** entity's query to the parent
*without loading the whole collection* — so `?filter`/`?sort`/`?page` apply against
the related type's vocabulary, and pagination windows in SQL. There are two
branches (ADR 0031):

- **FK fast-path** — a single-valued *inverse* association (the OneToMany case,
  where the related entity carries the owning foreign key). Scoped directly by that
  FK. In the example, `albums.tracks` takes this path: `Track` carries the owning
  `album` reference, so the related-track query is `WHERE resource.album = :parent`.
- **`IN`-subquery** — any other to-many (owning-side, or many-to-many on either
  side). Scoped by an `IN` subquery rooted on the parent that keeps the related
  entity as the **outer** query root, so the shared filter/sort/count/window
  machinery applies identically. In the example, `playlists.tracks` (a
  many-to-many — see [`Track`](../examples/music-catalog-symfony/src/Entity/Track.php)
  / [`Playlist`](../examples/music-catalog-symfony/src/Entity/Playlist.php)) takes
  this path.

The [`RelatedCollectionTest`](../examples/music-catalog-symfony/tests/RelatedCollectionTest.php)
exercises both branches plus an unpaginated baseline, and proves the related
collection filters/sorts against the *related* vocabulary (a `tracks` default
filter even hides the explicit track from `GET /albums/1/tracks`). The
relationship-endpoint behaviour around these collections — paginated defaults,
linkage rendering — is on [relationships.md](relationships.md).

### The polymorphic boundary

A **polymorphic to-many** (`MorphToMany`, whose `relatedTypes()` spans more than
one type) is a deliberate hard boundary: the Doctrine provider executes one scoped
query against a single related entity class, and a polymorphic collection's members
span entity classes, so there is no single query to run. `fetchRelatedCollection`
throws a `\LogicException` for it.

A host that needs a polymorphic to-many supplies a **custom provider** that resolves
the members across types. The example app does exactly this:
[`LibraryItemsProvider`](../examples/music-catalog-symfony/src/Provider/LibraryItemsProvider.php)
serves `GET /libraries/{id}/items` (a `MorphToMany` over `tracks`/`albums`/`artists`
— see [`LibraryResource`](../examples/music-catalog-symfony/src/Resource/LibraryResource.php))
by resolving each member through its per-type repository. See
[custom-data-providers.md](custom-data-providers.md) for the recipe and
[relationships.md](relationships.md) for the polymorphic rendering — link core
[relations](https://github.com/haddowg/json-api/blob/main/docs/relations.md) for
`MorphToMany` itself.

## `belongsToMany` pivot data

When a `belongsToMany` relation declares pivot fields — as real field definitions,
`->fields(Integer::make('position'), DateTime::make('addedAt')->readOnly(), …)` — the
Doctrine provider reads those join-table values and exposes them: rendered as
per-member `meta.pivot`, **sortable** as a zero-config `?sort` vocabulary on the
related endpoint (`?sort=position` auto-derives from the field), and — writable by
default — **settable from the linkage `meta`** (ADR 0046).

A pivot **filter**, by contrast, is **author-declared**, not auto-derived (a
behaviour change from ADR 0045 — bundle ADR 0067, a minor bump). To filter on a pivot
column, add a `Where` (or any value filter) to the relation's `withFilters()` whose
target column is **`pivot.`-prefixed**:

```php
BelongsToMany::make('orderedTracks')
    ->type('tracks')
    ->fields(Integer::make('position'), Integer::make('weight'))
    ->withFilters(
        Where::make('position', 'pivot.position'),   // filter[position] on the join column
        Where::make('weight', 'pivot.weight'),
    ),
```

— from the example's
[`PlaylistResource::orderedTracks`](../examples/music-catalog-symfony/src/Resource/PlaylistResource.php).
The `pivot.` column prefix routes the filter to the join alias (the cast is
auto-resolved from the backing pivot field); the wire `filter[<key>]` key is whatever
you name, independent of the column. The relation's `withFilters()`/`withSorts()` and
the `pivot.` convention are covered on
[relationships → pivot data](relationships.md#pivot-belongstomany-data).

**Pivot data only exists over an association entity.** A plain `#[ORM\ManyToMany]`
join table holds only the two foreign keys; Doctrine cannot map a `position` column
on it. So a pivot relation must be backed by an **association entity** — `Playlist
-> OneToMany -> PlaylistTrack(position, addedAt) -> ManyToOne -> Track`. The provider
**auto-detects** that entity from your metadata (`PivotAssociationResolver`: the one
to-many on the parent whose target also has a `ManyToOne` to the far type), or
honours an explicit `->through(PlaylistTrack::class)` when auto-detection is
ambiguous (two candidate entities) or finds none — in which case it throws a
`\LogicException` naming the relation.

The fetch is **one** DQL statement over the association entity, with the far entity
as the query root so the shared filter/sort/count/window machinery applies to the
related vocabulary unchanged, the pivot filters/sorts applied on the joined `pivot`
alias, and each declared field selected as a scalar that rides every row:

```sql
SELECT resource, pivot.position AS pivot_position, pivot.addedAt AS pivot_addedAt
FROM Track resource
INNER JOIN PlaylistTrack pivot WITH pivot.track = resource
WHERE pivot.playlist = :parent
  -- [AND related-entity filters on resource] [AND pivot filters on pivot.<field>]
ORDER BY -- [pivot.<field> | resource.<field>]
-- LIMIT/OFFSET
```

So the rendered pivot values come from the same query that scopes, filters, sorts and
paginates the page — no two-stage query and no page-shortening, so pagination is
correct.

A `?sort` mixing a pivot and a related field is applied in the **request's directive
order** across both aliases, so `?sort=position,title` orders by the pivot key first
and `?sort=title,position` by the related key first. Under **duplicate membership**
(the same far entity joined to the parent by more than one association row — a track
at two positions), the query `GROUP BY`s the far id: the page returns one row per
distinct member, the total is `COUNT(DISTINCT)`, and the rendered `meta.pivot` is a
single representative membership row (pivot meta is one value set per member, not a
list).

### Writing pivot fields (the association-entity diff)

A pivot field is writable unless `->readOnly()`. The Doctrine persister applies a
linkage's per-member `meta` as an **association-entity diff** over the same
auto-detected entity (the `PivotAssociationResolver`), on both the relationship
endpoints and a whole-resource write:

- **upsert** each incoming member — find the existing association row for
  `(parent, member)`; if present, update its writable pivot fields from `meta` **in
  place** (the reorder); if absent, create a new row (parent + member + the writable
  `meta` values; read-only fields take their server default, e.g. a `#[ORM\PrePersist]`
  timestamp);
- on `PATCH` (`Mode::Replace`) **remove** the rows whose member is no longer present
  (full sync); on `POST` (`Mode::Add`) leave the rest; on `DELETE` (`Mode::Remove`)
  remove the incoming members' rows (no `meta`);
- a **read-only** pivot field supplied in `meta` is never written; the values are
  coerced through each field's own cast, and the managed association entities are
  persisted/removed so the flush is storage-correct.

The `meta` is validated against the writable pivot fields' constraints (in the
operation's create/update context) before the diff runs — a violation is a `422`
pointed at the linkage meta, with no write.

**Boundaries.** Pivot is **Doctrine-only** — the in-memory provider has no association
entity, so a pivot key `400`s there, no pivot meta renders, and a pivot-meta **write
is ignored** (the relation is a plain to-many in-memory). A `belongsToMany` without
`fields()` keeps the plain related-collection scoping above. See
[relationships.md](relationships.md#pivot-belongstomany-data) for the resource
declaration, the rendered shape and the write convention.

## The load-state seam

`DoctrineRelationshipLoadState` powers a relation's lazy-linkage policy (ADR 0015 —
wired into core through `Server::withRelationshipLoadState()`). A to-many and a
`HasOne` are **lazy by default** (core ADR 0067); it answers, **without triggering a
load**, whether such a relation's linkage is already in memory, so a lazy relation
can omit its `data` member rather than force a lazy round-trip just to render
identifiers:

- a **to-many** is "loaded" only when its backing association is an
  already-initialised collection — a Doctrine `PersistentCollection`'s
  `isInitialized()` is consulted directly (it neither iterates nor initialises);
  a plain array / `ArrayCollection` (a fresh entity or an eager fetch) counts as
  loaded;
- a **to-one** is *always* loaded — a lazy `ManyToOne` proxy already carries its
  identifier, so emitting the linkage reads the foreign key off the proxy and
  never hits the database.

The example's albums→tracks relation relies on the lazy default — no opt-in needed:

```php
HasMany::make('tracks')
    ->type('tracks')
    ->paginate(PagePaginator::make()->withDefaultPerPage(2)),
```

A relation whose `column()` does not name a Doctrine association on the entity (or
a non-entity model the `EntityManager` does not manage) is treated as loaded, so
the predicate never changes behaviour for a relation it cannot reason about. In
non-Doctrine apps the seam is absent and core treats every relation as loaded.

Source: [`DoctrineRelationshipLoadState`](../src/Serializer/Doctrine/DoctrineRelationshipLoadState.php),
[ADR 0015](adr/0015-relationship-linkage-load-state-is-a-storage-aware-predicate.md).
The lazy-linkage rendering convention (and the `withData()` eager opt-in) is core's —
link [relations](https://github.com/haddowg/json-api/blob/main/docs/relations.md).

## Next / see also

- [The Provider/Persister SPI](data-layer.md) — the storage-agnostic interfaces the
  Doctrine classes implement, the per-type resolution, and the generic CRUD
  handler.
- [Custom providers, query extensions & the in-memory provider](custom-data-providers.md)
  — `DoctrineExtensionInterface` (the base-scope seam `PublishedAlbumsExtension`
  uses, whose `apply()` receives an `ExtensionContext` carrying the type, the
  `QueryPurpose` and the request-aware nullable `JsonApiRequestInterface`),
  overriding Doctrine per type, and the polymorphic escape hatch.
- [Relationship endpoints](relationships.md) — how the related/relationship
  endpoints and polymorphic rendering build on this layer.
- Core: [filters](https://github.com/haddowg/json-api/blob/main/docs/filters.md),
  [sorts](https://github.com/haddowg/json-api/blob/main/docs/sorts.md),
  [pagination](https://github.com/haddowg/json-api/blob/main/docs/pagination.md),
  [relations](https://github.com/haddowg/json-api/blob/main/docs/relations.md),
  [adapters](https://github.com/haddowg/json-api/blob/main/docs/adapters.md).
