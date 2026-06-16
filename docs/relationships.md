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
declares a to-one `album` and a to-many `playlists`:

```php
BelongsTo::make('album')->type('albums'),
BelongsToMany::make('playlists')
    ->type('playlists')
    ->fields(['position' => 'integer', 'addedAt' => 'datetime'])
    ->cannotReplace(),
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
