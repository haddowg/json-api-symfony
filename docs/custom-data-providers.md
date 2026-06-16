# Custom providers, query extensions & the in-memory provider

The Doctrine reference data layer ([doctrine.md](doctrine.md)) is a **fallback**, not
a requirement: it registers at tag priority `-128` and answers for every
entity-mapped type. Whenever you need something Doctrine can't give you — a store
that isn't Doctrine, a base query constraint the client must not be able to undo, a
type whose data isn't in any database, or a polymorphic to-many no single query can
express — you implement the SPI yourself and let autoconfiguration slot it in
**ahead** of the fallback.

This page is the how-to. It owns three things: the override recipe, the
`DoctrineExtensionInterface` query-scoping seam, and the in-memory provider as a
worked, reusable example. The SPI contracts themselves — the method signatures, the
resolution mechanics, `CollectionCriteria`/`CriteriaApplier` — live on
[data-layer.md](data-layer.md); this page references them rather than restating them.

## The override recipe

To take over a type, implement
[`DataProviderInterface`](../src/DataProvider/DataProviderInterface.php) (and/or
[`DataPersisterInterface`](../src/DataPersister/DataPersisterInterface.php)), return
`true` from `supports()` for that type, and let autoconfiguration tag the service.

```php
services:
    _defaults:
        autowire: true
        autoconfigure: true

    haddowg\JsonApiBundle\Examples\MusicCatalog\:
        resource: '../src/'
        # …
```

That's the whole wiring. Autoconfiguration tags any `DataProviderInterface` with
`haddowg.json_api.data_provider` (`JsonApiBundle::DATA_PROVIDER_TAG`) and any
`DataPersisterInterface` with `haddowg.json_api.data_persister`
(`DATA_PERSISTER_TAG`) at the **default priority `0`**. The bundled Doctrine
provider/persister register at `-128`, so the registry — which returns the first
provider whose `supports()` is true, in descending tag priority — picks yours for
the types it answers and falls through to Doctrine for the rest. No priority
configuration, no `decorates:`, no config key. (Resolution is owned by
[data-layer.md](data-layer.md#resolution-priority--first-supports-match).)

A custom provider **should** reuse the shared
[`CriteriaApplier`](../src/DataProvider/CriteriaApplier.php) so it stays
spec-conformant — it folds declared filter defaults, throws the right 400s for an
unrecognised `filter[…]`/`sort` key, and handles the `-` descending prefix. You only
own *execution* (how a filter becomes a `WHERE`, how a window becomes a slice).

### Overriding one type while keeping Doctrine for the rest

In the example app, [`OverridingArtistProvider`](../examples/music-catalog-symfony/src/Provider/OverridingArtistProvider.php)
takes over `artists` and delegates everything it doesn't special-case back to the
injected Doctrine provider, so the real `/artists` endpoint stays intact:

```php
final class OverridingArtistProvider implements DataProviderInterface
{
    public const string SENTINEL_ID = 'override';

    public function __construct(private readonly DoctrineDataProvider $doctrine) {}

    public function supports(string $type): bool
    {
        return $type === 'artists';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        if ($id === self::SENTINEL_ID) {
            return new Artist(id: self::SENTINEL_ID, name: self::NAME, slug: self::SENTINEL_ID, trackCount: 0);
        }

        return $this->doctrine->fetchOne($type, $id);
    }

    // fetchCollection / fetchRelatedCollection delegate straight to $this->doctrine …
}
```

The Doctrine provider is still registered (the `ArtistResource` maps an entity); the
override wins **by priority**, not by the fallback being absent. Injecting
`DoctrineDataProvider` directly is the idiomatic way to build a thin overlay on top
of the reference path. [`CustomProviderTest`](../examples/music-catalog-symfony/tests/CustomProviderTest.php)
asserts the registry resolves `OverridingArtistProvider` for `artists` while
`DoctrineDataProvider` is still in the container, and that reading the sentinel id —
which no seeded row carries — returns a `200` attributable to the override alone.

## Reference / static-data providers

The simplest custom provider serves a type whose data is **not in any database** — a
fixed list, a dataset from a library, or a backed enum's cases. Pair a tiny
`DataProvider` with a standalone `#[AsJsonApiSerializer]` (the serializer owns the
wire shape — see [custom-serializers-hydrators.md](custom-serializers-hydrators.md))
and you have a **read-only** type with no entity, no hydrator, no persister.

The example app's `countries` type does exactly this.
[`CountryProvider`](../examples/music-catalog-symfony/src/Provider/CountryProvider.php)
sources its rows from `symfony/intl`'s `Countries` (id = ISO 3166-1 alpha-2 code,
attribute = the localized name) and the standalone
[`CountrySerializer`](../examples/music-catalog-symfony/src/Serializer/CountrySerializer.php)
renders them, opened to just the two GET operations:

```php
#[AsJsonApiSerializer(type: 'countries', operations: [Operation::FetchCollection, Operation::FetchOne])]
final class CountrySerializer extends AbstractSerializer implements UriTypeAwareInterface
{
    // getType()/getId()/getAttributes() over the Country model …
}
```

A non-database source is a **first-class collection, not a special case**: it still
serves `filter` / `sort` / `pagination`. There is one wrinkle. The handler normally
sources its filter/sort vocabulary from the type's `AbstractResource`, but a
resource-less type declares no field inventory, so on that null-resource path (see
[data-layer.md](data-layer.md#typemetadataresolver--tolerating-a-bare-pair)) the
handler has none to hand the provider. The provider therefore **declares its own
vocabulary and rebuilds the criteria around it**, then runs the shared
`CriteriaApplier` exactly as Doctrine and the in-memory provider do:

```php
public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
{
    $vocabularyCriteria = new CollectionCriteria(
        $criteria->queryParameters,
        [Where::make('name', 'name', 'like')],
        [SortByField::make('name', 'name')],
        $criteria->window,
    );

    /** @var list<object> $items */
    $items = $this->applier->apply($vocabularyCriteria, $this->all(), $this->filterHandler, $this->sortHandler);

    return $this->window($items, $criteria->queryParameters);
}
```

Pagination is likewise driven from the request's `page[number]`/`page[size]` and
executed as an `OffsetWindow` `array_slice`, since a resource-less type has no
server-default paginator. `Where`/`SortByField` are core's filter/sort VOs (see core
[filters.md](https://github.com/haddowg/json-api/blob/main/docs/filters.md) and
[sorts.md](https://github.com/haddowg/json-api/blob/main/docs/sorts.md)); the
in-memory filter/sort handlers the applier delegates to are core's
[`ArrayFilterHandler`/`ArraySortHandler`](https://github.com/haddowg/json-api/blob/main/docs/adapters.md).

[`ReferenceDataTest`](../examples/music-catalog-symfony/tests/ReferenceDataTest.php)
proves the whole ICU country list renders with no database behind it, that
`filter[name]=United King` resolves to `GB` via the applier, that `sort=name` /
`-name` reverse, that `page[size]=2` slices into disjoint pages, that an undeclared
filter key `400`s, and that only the two GET routes are emitted.

For the truly fixed case there's an even simpler variant: a **backed enum**. Each
case is a row, its value is the id, and a four-line `fetchOne`/`fetchCollection` over
`SomeEnum::cases()` paired with a serializer exposes it read-only — no model class
needed at all.

## `DoctrineExtensionInterface` — scoping the Doctrine queries

When you want to keep the Doctrine reference path but constrain *every* query it
builds for a type — a soft-delete exclusion, tenant scoping, a published-only view,
or an eager-load join — implement
[`DoctrineExtensionInterface`](../src/DataProvider/Doctrine/DoctrineExtensionInterface.php)
rather than replacing the provider:

```php
interface DoctrineExtensionInterface
{
    public function supports(string $type): bool;

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder;
}
```

Autoconfiguration tags it `haddowg.json_api.doctrine_extension`
(`JsonApiBundle::DOCTRINE_EXTENSION_TAG`). Every extension whose `supports()` matches
runs in **descending tag priority, before the requested criteria are applied**. That
ordering is the whole point:

- a client `filter[…]`/`sort` always composes **on top of** (`AND` onto) your scope —
  it can never re-widen it;
- the pre-window `COUNT` of a paginated fetch is taken from the **scoped** builder, so
  totals agree with what's visible;
- a single fetch whose row your scope excludes yields `null` → a JSON:API **`404`**,
  so `GET /{type}/{id}` is scoped exactly like the collection.

The example app's [`PublishedAlbumsExtension`](../examples/music-catalog-symfony/src/Query/PublishedAlbumsExtension.php)
scopes every `albums` query to `published = true`:

```php
final class PublishedAlbumsExtension implements DoctrineExtensionInterface
{
    public function supports(string $type): bool
    {
        return $type === 'albums';
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        $alias = $builder->getRootAliases()[0]
            ?? throw new \LogicException('The builder arrived without a root alias.');

        return $builder
            ->andWhere(\sprintf('%s.published = :published_only', $alias))
            ->setParameter('published_only', true);
    }
}
```

[`DoctrineExtensionTest`](../examples/music-catalog-symfony/tests/DoctrineExtensionTest.php)
seeds three albums (two published, one not) and asserts the unpublished one never
appears in `/albums`, that `filter[tracks]=1` ANDs onto the scope rather than
re-widening it, and that `GET /albums/3` is a `404` *while the row still exists in
the database* — proof the same extension pipeline runs for the single fetch.

### The `ExtensionContext`

`apply()` receives an
[`ExtensionContext`](../src/DataProvider/Doctrine/ExtensionContext.php) carrying
everything the bundle knows about the query being built:

```php
final readonly class ExtensionContext
{
    public function __construct(
        public string $type,                     // the resource type being scoped
        public QueryPurpose $purpose,            // why the query is built
        public ?JsonApiRequestInterface $request = null, // the parsed request, or null
    ) {}
}
```

`$request` is the seam for **request-aware** scoping — read a query parameter or
header off the JSON:API request and branch on it. It is populated on the
related/include/batch loads (each of which carries the request on its SPI
signature) and is `null` on the primary `FetchOne`/`FetchCollection` loads (whose
SPI carries no request). Branch on it only to *add* a constraint, falling through to
your unconditional base scope when it is absent, so the primary fetch stays scoped.

### The `QueryPurpose` fail-closed contract

`$context->purpose` ([`QueryPurpose`](../src/DataProvider/Doctrine/QueryPurpose.php))
tells you *why* the query is being built — `FetchCollection` (the primary
`GET /{type}` collection), `FetchOne` (`GET /{type}/{id}`), or
`FetchRelatedCollection` (any related/include/batch load of the type while serving
another type's request, so a request-aware scope can tell a primary collection from a
related load of the same type). It is **non-exhaustive by design**: the write phase
fetches an update's or delete's target through the same extension pipeline, so a
scoping rule holds for writes without re-declaration — but that means new purposes can
appear.

So **apply your constraint unconditionally** and branch on a purpose only to *exempt*
one you have a specific reason to treat differently. An exhaustive `match` over the
purpose would silently stop applying your tenancy or soft-delete scope the moment a
new purpose is added — scoping must fail **closed**. `PublishedAlbumsExtension`
ignores the purpose entirely, which is the common case.

Two practical contracts when you write `apply()`:

- **Bound parameters are yours to name.** Any name not prefixed `jsonapi_` is safe —
  the bundle's own handlers generate theirs under that reserved prefix, so a
  collision is impossible. `PublishedAlbumsExtension` uses `:published_only`.
- **The builder arrives ready.** The root entity is selected (the alias is readable
  via `$builder->getRootAliases()[0]`) and, for `QueryPurpose::FetchOne`, the
  identifier constraint is already bound.

## Eager-loading `?include` is automatic (no extension, no extra dependency)

You do **not** need a `DoctrineExtensionInterface` eager-load join to avoid N+1ing
the `?include` tree, and there is **no library to install** — include batching is
built into the bundle (it used to require an external preloader; that dependency is
gone). The bundle batch-loads a read's effective include tree automatically — one
query per relation per level, reusing core's include decision so it loads exactly
what is rendered (requested `?include` **or** a resource's
`getDefaultIncludedRelationships()` fallback when none is sent). It degrades to a
lazy load for any relation it cannot batch (polymorphic, computed, composite-key).
See [Eager-loading includes](doctrine.md#eager-loading-includes-no-n1) on the
Doctrine page and
[ADR 0062](adr/0062-load-plain-includes-through-the-batched-related-fetch.md).

Reach for an extension's eager-load join only for a relation you always want loaded
**regardless of `?include`** (a join the serializer or your own code needs on every
read), or to optimise a specific access shape the per-level batch does not cover.

A custom provider participates in the same automatic batching by implementing
[`fetchRelatedCollectionBatch()`](../src/DataProvider/DataProviderInterface.php) — the provider-agnostic
`RelatedIncludeBatcher` orchestrates the include tree level by level and calls that
seam per included relation over the level's parents. A provider whose
`fetchRelatedCollectionBatch()` returns an empty batch for a relation (or that
cannot batch a given relation) simply renders that relation's includes lazily.

## The in-memory provider as a worked example

[`InMemoryDataProvider`](../src/DataProvider/InMemoryDataProvider.php),
[`InMemoryDataPersister`](../src/DataPersister/InMemoryDataPersister.php) and
[`InMemoryStore`](../src/DataProvider/InMemoryStore.php) live in **`src/`** (not
`tests/`) precisely so they're a documented, reusable example — mirroring how core
ships its `InMemory\Array{Filter,Sort}Handler`. They're the in-memory analogue of a
real adapter's read half + write half + the database both talk to, and they're the
fastest way to stand a type up without a schema.

One instance answers for a single `$type`. The provider construction is:

```php
new InMemoryDataProvider(
    string $type,
    iterable $items,            // objects keyed by id
    ?\Closure $identify = null, // reads an item's id; required ONLY for writes
    ?\Closure $assignId = null, // writes a minted id onto an item (store-provided/auto-increment ids on create)
    string $idColumn = 'id',    // the member the cursor (keyset) page reads as the PK tiebreaker
);
```

Reads need only the seed map; the `$identify` closure is consulted only when a
persister writes through the store, and `$assignId`/`$idColumn` are write-path /
keyset concerns — `$assignId` lets the shared store mint a store-provided
(auto-increment) id on an id-less create, and `$idColumn` names the model member the
cursor (keyset) page reads as its primary-key tiebreaker (defaults to `id`). Pair it
with a persister over the **same** store (via `$provider->store()`) so a create is
immediately readable:

```php
new InMemoryDataPersister(
    string $type,
    InMemoryStore $store,                       // the read provider's store()
    \Closure $factory,                          // builds the blank create instance
    ?\Closure $relatedResolver = null,          // (type, id) → ?object, for relationship mutation
);
```

The example app is Doctrine end-to-end, but the bundle's own conformance kernels show
the copyable factory-plus-tag wiring. A factory method builds the seeded pair:

```php
public static function createProvider(): InMemoryDataProvider
{
    // … build $articles keyed by id …
    return new InMemoryDataProvider('articles', $articles, static function (object $item): string {
        \assert($item instanceof Article);

        return $item->id;
    });
}

public static function createPersister(InMemoryDataProvider $provider): InMemoryDataPersister
{
    return new InMemoryDataPersister('articles', $provider->store(), static fn(): Article => new Article());
}
```

and the kernel registers each as a service, hands the persister the **same**
provider, and applies the two tags:

```php
$services->set('test.articles_provider', InMemoryDataProvider::class)
    ->factory([WritableArticleFactory::class, 'createProvider'])
    ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

// The persister shares the provider's store, so writes are readable.
$services->set('test.articles_persister', InMemoryDataPersister::class)
    ->factory([WritableArticleFactory::class, 'createPersister'])
    ->args([service('test.articles_provider')])
    ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
```

A factory is needed because the seed objects can't be service-configuration argument
literals. Both tags register at the default priority `0`, so this pair shadows the
Doctrine fallback for `articles` with no further config.

### The `$relatedResolver` requirement

A persister with **no** `$relatedResolver` supports only whole-resource writes
(create/update/delete). The store holds related **objects**, not raw ids, so
`mutateRelationship()` needs a `(type, id) → ?object` closure to turn an incoming
linkage id back into the stored object before setting it on the parent — without one,
it throws. Wire it as a lookup across the related types' stores:

```php
return new InMemoryDataPersister(
    'articles',
    self::articles()->store(),
    static fn(): Article => new Article(),
    static function (string $type, string $id) use ($authors, $comments): ?object {
        return match ($type) {
            'authors' => $authors->store()->find($id),
            'comments' => $comments->store()->find($id),
            default => null,
        };
    },
);
```

(Relationship mutation itself is owned by [relationships.md](relationships.md); the
`mutateRelationship()` signature is on [data-layer.md](data-layer.md).)

### The `$request` argument in `fetchRelatedCollection`

`fetchRelatedCollection()` is handed a
[`JsonApiRequestInterface`](https://github.com/haddowg/json-api/blob/main/docs/relations.md)
the **Doctrine provider ignores** (it pushes the scope down into a `QueryBuilder`) but
the **in-memory provider uses** — it reads the related objects straight off the parent
via the relation's public accessor:

```php
$related = $relation->readValue($parent, $request);
```

`readValue()` honours a relation's `storedAs`/`extractUsing` (core
[relations.md](https://github.com/haddowg/json-api/blob/main/docs/relations.md)), so a
custom `extractUsing` extractor that consults the request gets it here. If you write a
provider that resolves related collections off the parent object, this is the argument
you thread through.

## Replacing Doctrine for a polymorphic to-many

The reference Doctrine provider **throws** on a polymorphic `MorphToMany` related
collection — its members span entity classes, so no single scoped query can express
it (see [doctrine.md](doctrine.md) and [relationships.md](relationships.md)). The
escape hatch is a custom provider that resolves the members across their per-type
repositories.

The example app's [`LibraryItemsProvider`](../examples/music-catalog-symfony/src/Provider/LibraryItemsProvider.php)
does this for `libraries.items` (a mixed `Track | Album | Artist` collection). It
delegates the parent fetch to the Doctrine provider, then populates the non-mapped
`Library::$items` by looking each member up in the right repository — sharing the
`EntityManagerInterface` so every resolved row comes back **managed**:

```php
private function resolveItems(string $libraryId): array
{
    $entityClassByType = ['tracks' => Track::class, 'albums' => Album::class, 'artists' => Artist::class];

    $items = [];
    foreach (self::ITEMS_BY_LIBRARY[$libraryId] ?? [] as $pointer) {
        $entityClass = $entityClassByType[$pointer['type']] ?? null;
        if ($entityClass === null) {
            continue;
        }

        $member = $this->entityManager->getRepository($entityClass)->find($pointer['id']);
        if ($member !== null) {
            $items[] = $member;
        }
    }

    return $items;
}
```

A polymorphic to-many carries no shared filter/sort vocabulary, so the criteria here
only ever window — `LibraryItemsProvider` reuses `CriteriaApplier` (a no-op for the
absent filters/sorts) and an `OffsetWindow` slice, exactly like the in-memory
provider. The same shape resolves a polymorphic **to-one** (`MorphTo`): the example's
[`FavoriteProvider`](../examples/music-catalog-symfony/src/Provider/FavoriteProvider.php)
fills a `Favorite`'s non-mapped target from its stored `targetType`/`targetId` pair.

---

## Next / See also

- [data-layer.md](data-layer.md) — the SPI contracts, the resolution registry, and the
  `CrudOperationHandler` these providers plug into.
- [doctrine.md](doctrine.md) — the reference implementation you're overriding or
  scoping, and the polymorphic-to-many boundary.
- [custom-serializers-hydrators.md](custom-serializers-hydrators.md) — the standalone
  serializer that pairs with a reference-data provider.
- [relationships.md](relationships.md) — related/relationship endpoints and the
  polymorphic rendering a custom provider feeds.
- Core [adapters.md](https://github.com/haddowg/json-api/blob/main/docs/adapters.md) —
  the `FilterHandlerInterface`/`SortHandlerInterface` semantics and the array handlers
  the in-memory provider delegates to.
