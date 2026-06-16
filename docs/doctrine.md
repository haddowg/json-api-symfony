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

When the optional [`shipmonk/doctrine-entity-preloader`](https://github.com/shipmonk-rnd/doctrine-entity-preloader)
library is installed, eager-loading of a read's `?include` tree is **automatic** —
you install the library and includes stop N+1ing, with no per-type code. The
provider batch-loads the included relationships Laravel-style: **one query per
relation per level**, no fetch-joins. Each level loads a relation for *every* source
entity in a single `WHERE id IN (…)`-style query, and the loaded targets seed the
next level.

Over the example, `GET /albums?include=tracks` across 16 albums issues 2
include-load queries (the albums, then one batched tracks load) — not the `1 + N` a
lazy render issues:

```http
GET /albums?include=tracks&page[size]=100
```

The preloader reuses **core's** include decision, so it preloads exactly what is
rendered. This includes **default includes**: a resource's
`getDefaultIncludedRelationships()` is applied by core as a *fallback* — when the
request sends no `?include`, the listed relationships are included (and now
preloaded); an explicit `?include` (even an empty `?include=`) overrides the default.

```php
final class AlbumResource extends AbstractResource
{
    // GET /albums with no ?include yields each album's artist in `included`
    // (rendered AND batch-preloaded); ?include=… or ?include= overrides it.
    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return ['artist'];
    }
}
```

— from [`AlbumResource`](../examples/music-catalog-symfony/src/Resource/AlbumResource.php).

Preloading is a **pure optimization**: the rendered document is identical with or
without it. So a relation the preloader cannot batch silently falls back to a lazy
load — a polymorphic relation (more than one related type), a computed /
`extractUsing` / aliased non-association column, or a composite-key target. The
relation's storage column drives the batch (`column() ?? name()`), so a `storedAs()`
rename is honoured.

The capability is **opt-in**: it is wired only when the library is present (a
`suggest` dependency); without it the provider degrades to lazy includes. See
[ADR 0035](adr/0035-doctrine-include-batch-preloading.md) and
[`IncludePreloader`](../src/DataProvider/Doctrine/IncludePreloader.php); the witness
is [`IncludePreloadTest`](../examples/music-catalog-symfony/tests/IncludePreloadTest.php).

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
- **`WhereHas`/`WhereDoesntHave` are relationship-existence filters** (ADR 0019).
  They ignore the request value and match rows whose named association has (or
  lacks) at least one related row, pushed down as a **correlated `EXISTS`
  subquery** that re-roots on the same entity, joins the association, and
  correlates back to the outer root. This is set-membership, not a join, so primary
  rows are neither duplicated nor in need of `DISTINCT`, and a to-one and a to-many
  translate identically.

The example `AlbumResource` declares an existence filter:

```php
public function filters(): array
{
    return [
        WhereHas::make('tracks'),
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

## The load-state seam

`DoctrineRelationshipLoadState` powers a relation's `linkageOnlyWhenLoaded()`
policy (ADR 0015 — wired into core through
`Server::withRelationshipLoadState()`). It answers, **without triggering a load**,
whether a relation's linkage is already in memory, so a relation that opted in can
omit its `data` member rather than force a lazy round-trip just to render
identifiers:

- a **to-many** is "loaded" only when its backing association is an
  already-initialised collection — a Doctrine `PersistentCollection`'s
  `isInitialized()` is consulted directly (it neither iterates nor initialises);
  a plain array / `ArrayCollection` (a fresh entity or an eager fetch) counts as
  loaded;
- a **to-one** is *always* loaded — a lazy `ManyToOne` proxy already carries its
  identifier, so emitting the linkage reads the foreign key off the proxy and
  never hits the database.

The example uses it on the albums→tracks relation:

```php
HasMany::make('tracks')
    ->type('tracks')
    ->paginate(PagePaginator::make()->withDefaultPerPage(2))
    ->linkageOnlyWhenLoaded(),
```

A relation whose `column()` does not name a Doctrine association on the entity (or
a non-entity model the `EntityManager` does not manage) is treated as loaded, so
the predicate never changes behaviour for a relation it cannot reason about. In
non-Doctrine apps the seam is absent and core treats every relation as loaded.

Source: [`DoctrineRelationshipLoadState`](../src/Serializer/Doctrine/DoctrineRelationshipLoadState.php),
[ADR 0015](adr/0015-relationship-linkage-load-state-is-a-storage-aware-predicate.md).
The `linkageOnlyWhenLoaded()` rendering convention is core's — link
[relations](https://github.com/haddowg/json-api/blob/main/docs/relations.md).

## Next / see also

- [The Provider/Persister SPI](data-layer.md) — the storage-agnostic interfaces the
  Doctrine classes implement, the per-type resolution, and the generic CRUD
  handler.
- [Custom providers, query extensions & the in-memory provider](custom-data-providers.md)
  — `DoctrineExtensionInterface` (the base-scope seam `PublishedAlbumsExtension`
  uses), overriding Doctrine per type, and the polymorphic escape hatch.
- [Relationship endpoints](relationships.md) — how the related/relationship
  endpoints and polymorphic rendering build on this layer.
- Core: [filters](https://github.com/haddowg/json-api/blob/main/docs/filters.md),
  [sorts](https://github.com/haddowg/json-api/blob/main/docs/sorts.md),
  [pagination](https://github.com/haddowg/json-api/blob/main/docs/pagination.md),
  [relations](https://github.com/haddowg/json-api/blob/main/docs/relations.md),
  [adapters](https://github.com/haddowg/json-api/blob/main/docs/adapters.md).
