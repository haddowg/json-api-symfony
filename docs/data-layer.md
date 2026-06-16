# The Provider/Persister SPI and the generic CRUD handler

Every JSON:API endpoint the bundle serves — read, write, related, relationship —
goes through one storage-agnostic data layer. Two tagged SPIs, resolved **per
type**, and a single generic handler that drives both. You never write a
controller, and you never write a per-type operation handler: the bundle ships
one [`CrudOperationHandler`](../src/Operation/CrudOperationHandler.php) that
dispatches the whole [operation set](https://github.com/haddowg/json-api/blob/main/docs/operations.md)
over the SPIs.

This page is the hub for Section D. It is the mental model you need before
reading [the Doctrine reference adapter](doctrine.md) (the zero-config default)
or [writing your own provider](custom-data-providers.md). The core vocabulary
this page builds on — [filters](https://github.com/haddowg/json-api/blob/main/docs/filters.md),
[sorts](https://github.com/haddowg/json-api/blob/main/docs/sorts.md),
[pagination](https://github.com/haddowg/json-api/blob/main/docs/pagination.md),
the [response value objects](https://github.com/haddowg/json-api/blob/main/docs/responses.md) —
is owned by core and linked, not re-explained.

## The two SPIs

Data access is split in half. Reads go through a
[`DataProviderInterface`](../src/DataProvider/DataProviderInterface.php); writes
go through a [`DataPersisterInterface`](../src/DataPersister/DataPersisterInterface.php).
Each is a Symfony service, auto-tagged by autoconfiguration, and resolved per
type by a registry. A type that registers neither has no endpoints; a type with a
provider but no persister is read-only; a type with both is full CRUD. Which
endpoints exist falls out of which capabilities you wire — see
[capability composition](capability-composition.md).

### `DataProviderInterface` — the read SPI

```php
interface DataProviderInterface
{
    public function supports(string $type): bool;

    public function fetchOne(string $type, string $id): ?object;

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult;

    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult;
}
```

- `supports()` tells the registry which type(s) this provider answers for.
- `fetchOne()` returns the single resource or `null` — the handler maps `null` to
  a JSON:API `404`.
- `fetchCollection()` receives a fully-resolved `CollectionCriteria` (below) and
  returns a `CollectionResult`.
- `fetchRelatedCollection()` is the related-endpoint twin of `fetchCollection()`:
  the to-many collection reachable from `$parent` through `$relation`, scoped to
  the parent then filtered/sorted/windowed per the **related** type's vocabulary
  ([relationships](relationships.md) covers the endpoint; [doctrine](doctrine.md)
  covers the push-down).

The interface is `@template-covariant TEntity of object`. A single-type provider
is `DataProviderInterface<Album>`; a multi-type provider (like the Doctrine one)
is `DataProviderInterface<object>`. The covariance lets the registry hold a
heterogeneous set as `DataProviderInterface<object>` while a single-model provider
stays precisely typed.

Tagged `haddowg.json_api.data_provider`
([`JsonApiBundle::DATA_PROVIDER_TAG`](../src/JsonApiBundle.php)). Implement the
interface, return `true` from `supports()` for your type, and autoconfiguration
tags the service — no manual registration.

### `DataPersisterInterface` — the write SPI

```php
interface DataPersisterInterface
{
    public function supports(string $type): bool;

    public function instantiate(string $type): object;

    public function create(string $type, object $entity): object;

    public function update(string $type, object $entity): object;

    public function delete(string $type, object $entity): void;

    public function mutateRelationship(
        string $type,
        object $entity,
        RelationInterface $relation,
        ToOneRelationship|ToManyRelationship $linkage,
        Mode $mode,
        bool $flush = true,
    ): object;
}
```

- `instantiate()` hands the handler a blank instance for the hydrator to populate
  on create. The persister owns the storage mapping, so it owns instantiation
  (ADR 0010) — this is why the [Doctrine persister](doctrine.md) can build
  constructor-less entities (`ClassMetadata::newInstance()`).
- `create()`/`update()` commit and return the entity; `delete()` returns nothing.
  Entities flow as plain `object` — the handler resolves the persister by type and
  never needs a narrower static type, so (unlike the covariant read provider) this
  contract is not templated.
- `mutateRelationship()` applies a relationship-endpoint mutation. Core has
  already loaded the parent and validated the request shape; the persister
  resolves the linkage's resource-identifier ids to the actual related
  objects/references and mutates the association under the
  [`Mode`](https://github.com/haddowg/json-api/blob/main/docs/relationship-mutation.md)
  — one of `Replace`, `Add` or `Remove` (the
  [handler match block](#the-crudoperationhandler) maps the three relationship
  operations onto these cases). The `ToOneRelationship`/`ToManyRelationship`
  linkage VOs are core's too. The same seam is reused for relationships embedded
  in a whole-resource write — see [relationships](relationships.md) for the
  endpoint, [validation](validation.md) for the write path's validation hooks.

#### The `$flush` subtlety

`mutateRelationship()` carries `bool $flush = true`. Relationship **endpoints**
commit per mutation (`$flush = true`). But a **whole-resource write** that embeds
relationships in `data.relationships` applies each one with `$flush = false`, so
the single `create()`/`update()` owns the commit — a not-yet-persisted create
target is never flushed mid-association (ADR 0018). You only need this distinction
when writing a custom persister; the handler sets it for you.

Tagged `haddowg.json_api.data_persister`
([`JsonApiBundle::DATA_PERSISTER_TAG`](../src/JsonApiBundle.php)).

## Resolution: priority + first-supports-match

Both registries —
[`DataProviderRegistry`](../src/DataProvider/DataProviderRegistry.php) and
[`DataPersisterRegistry`](../src/DataPersister/DataPersisterRegistry.php) —
resolve a type the same way: walk the tagged services in **descending tag
`priority`** order (the standard `tagged_iterator` semantics) and return the
**first** whose `supports()` is `true`.

```php
public function forType(string $type): DataProviderInterface
{
    foreach ($this->providers as $provider) {
        if ($provider->supports($type)) {
            return $provider;
        }
    }

    throw new \LogicException(\sprintf('No JSON:API data provider is registered for type "%s".', $type));
}
```

This is the override recipe. The bundled Doctrine provider and persister register
at **`-128`** — always last, always the fallback. An application provider tagged
at the default priority (`0`) sorts ahead of Doctrine and **shadows** it for the
types its `supports()` claims, with **no configuration at all**. The example app's
[`OverridingArtistProvider`](../examples/music-catalog-symfony/src/Provider/OverridingArtistProvider.php)
is exactly this shape — a default-priority provider for `artists` that wins by
priority over the still-wired Doctrine fallback:

```php
final class OverridingArtistProvider implements DataProviderInterface
{
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

    // …delegate fetchCollection / fetchRelatedCollection to the Doctrine provider
}
```

A couple of things to know:

- **Priority is a tag attribute, not registry behaviour.** The registry trusts the
  injected iteration order; the container sorts. Raise a provider above `0` only if
  it must beat another non-default provider, never to beat Doctrine.
- **No match is a `LogicException`, never a `404`.** A registered type with no data
  source is a wiring bug surfaced at request time, distinct from a `404` (a row
  that does not exist). Compile-time guards catch the common cases earlier — see
  [resources](resources.md) and [capability composition](capability-composition.md).

[Custom providers](custom-data-providers.md) walks through the full override
how-to, including reusing the criteria applier so a shadow stays spec-conformant.

## The collection criteria

A `fetchCollection()` (and `fetchRelatedCollection()`) call receives a
[`CollectionCriteria`](../src/DataProvider/CollectionCriteria.php) — everything the
provider needs to answer, pre-resolved by the handler so providers stay decoupled
from core's `AbstractResource` API:

```php
final readonly class CollectionCriteria
{
    public function __construct(
        public QueryParameters $queryParameters,
        public array $filters = [],   // list<FilterInterface> — the DECLARED vocabulary
        public array $sorts = [],     // list<SortInterface>   — the DECLARED vocabulary
        public ?WindowInterface $window = null,
    ) {}
}
```

`$filters`/`$sorts` are the **declared** vocabularies (the resource's `filters()`
/ `allSorts()`) — what the requested `filter[…]`/`sort` keys are matched against,
not the request itself. `$window` is the pagination fetch window to push down to
the store, or `null` for an unpaginated fetch; it is the polymorphic
`WindowInterface`, and a count-based provider narrows to the `OffsetWindow` it can
execute.

### `CriteriaApplier` — spec semantics, decided once

[`CriteriaApplier`](../src/DataProvider/CriteriaApplier.php) is the shared half of
a collection fetch. It matches the requested keys against the declared
vocabularies and applies each match to a provider-native query through the
provider's core [filter/sort handlers](https://github.com/haddowg/json-api/blob/main/docs/adapters.md),
threading the query value through (`TQuery` is a `QueryBuilder` for Doctrine, a
`list` in memory):

```php
public function apply(
    CollectionCriteria $criteria,
    mixed $query,
    FilterHandlerInterface $filterHandler,
    SortHandlerInterface $sortHandler,
): mixed
```

Because every provider runs this same matching, the spec semantics are decided in
one place and a provider only ever differs in *execution*:

- declared filter defaults folded into the requested map (an absent key takes its
  filter's declared default; a requested key always wins) via core's
  `FilterDefaults`;
- an unknown filter key → `400` (`FilterParamUnrecognized`);
- sorting against an empty sort vocabulary → `400` (`SortingUnsupported`);
- an unknown sort field → `400` (`SortParamUnrecognized`);
- the `-` prefix → descending; sorts passed as one composite call (they do not
  compose commutatively, so the handler owns the combination).

**Pagination windowing is the provider's job, not the applier's.** The applier
never slices — how a window executes (`LIMIT`/`OFFSET` vs `array_slice`) is the
provider's concern. This is deliberate: it keeps the in-memory provider an
attributable conformance witness for Doctrine (both run the identical applier;
only the window execution differs). A custom provider **should** reuse
`CriteriaApplier` to stay spec-conformant — see
[custom providers](custom-data-providers.md).

### `CollectionResult`

A provider answers with a
[`CollectionResult`](../src/DataProvider/CollectionResult.php) — the materialized
`->items` plus an optional `->total`:

```php
final readonly class CollectionResult
{
    public function __construct(
        public iterable $items,
        public ?int $total = null,
    ) {}
}
```

`$total` is non-null **exactly when the fetch was windowed**, and it is the count
of the whole filtered collection *before* windowing — never `count($items)`. The
handler needs it to build the page (`links`/`meta.page` derive from it). An
unpaginated fetch leaves it `null` and the handler renders a plain collection
document.

## The `CrudOperationHandler`

[`CrudOperationHandler`](../src/Operation/CrudOperationHandler.php) is the generic,
zero-per-type-handler CRUD engine. The
[`ServerFactory`](../src/Server/ServerFactory.php) wires it via
`Server::withHandler()`, so `Server::dispatch($operation)` has a target. It
implements core's `OperationHandlerInterface` and dispatches on the operation type:

```php
return match (true) {
    $operation instanceof FetchResourceOperation       => $this->fetch($operation),
    $operation instanceof FetchRelatedOperation         => $this->fetchRelated($operation),
    $operation instanceof FetchRelationshipOperation    => $this->fetchRelationship($operation),
    $operation instanceof CreateResourceOperation       => $this->create($operation),
    $operation instanceof UpdateResourceOperation       => $this->update($operation),
    $operation instanceof DeleteResourceOperation       => $this->delete($operation),
    $operation instanceof UpdateRelationshipOperation   => $this->mutateRelationship($operation, $operation->body(), Mode::Replace),
    $operation instanceof AddToRelationshipOperation    => $this->mutateRelationship($operation, $operation->body(), Mode::Add),
    $operation instanceof RemoveFromRelationshipOperation => $this->mutateRelationship($operation, $operation->body(), Mode::Remove),
    default                                              => ErrorResponse::fromException(new ResourceNotFound()),
};
```

It is constructed with the two registries, the
[`TypeMetadataResolver`](../src/Server/TypeMetadataResolver.php) seam (below), and
an **optional** `ResourceValidator` — `null` when `symfony/validator` is not
installed, in which case writes run unvalidated. See [validation](validation.md)
for the validator and the silent-absence caveat.

### Reads

`fetch()` resolves the provider, then:

- a single fetch (`/{type}/{id}`) calls `fetchOne()` and maps `null` to a `404`,
  else renders `DataResponse::fromResource()`;
- a collection fetch resolves the resource's `filters()`/`allSorts()`/
  `pagination()` into a `CollectionCriteria`, calls `fetchCollection()`, and
  renders `DataResponse::fromPage()` when paginated (else
  `DataResponse::fromCollection()`).

The **singular-filter collapse**: if the client applied a filter the resource
declares [singular](https://github.com/haddowg/json-api/blob/main/docs/filters.md)
(`SupportsSingular`), the collection collapses to a zero-to-one response — the
first match or `null`, never an array, never paginated (core ADR 0039). The
example app's `artists` declares a singular `slug` filter; `GET
/artists?filter[slug]=radiohead` renders a single resource, and a no-match renders
`data: null` ([`ReadQueryTest`](../examples/music-catalog-symfony/tests/ReadQueryTest.php)).

### Writes

Writes share one shape: resolve the persister, drive core's per-type hydrator
(`Server::hydratorFor()`), commit, render.

- `create()` validates the body, hydrates a fresh `instantiate()` instance, applies
  any embedded relationships, persists, and renders `201` with a `Location` header.
- `update()` loads the target through the read provider (`404` when absent),
  validates, hydrates onto it, applies relationships, persists, renders `200`.
- `delete()` loads the target (`404` when absent), deletes, renders `204`
  (`NoContentResponse::create()`).

The `Location` uses the resource's URI segment (`uriType()`, see
[custom serializers/hydrators](custom-serializers-hydrators.md)) so it matches the
route the client will `GET`; a bare pair with no resource falls back to the type.
[`WriteTest`](../examples/music-catalog-symfony/tests/WriteTest.php) exercises all
three statuses and the `404`s end to end over Doctrine.

#### The relationship-strip subtlety

Core's hydrator populates id + attributes; a `data.relationships` member is **not**
hydrated by core (a scalar linkage id would land on a typed association property).
So the handler:

1. extracts the writable relationships from the body
   (`extractRelationships()`, reapplying core's read-only relationship gate);
2. strips `data.relationships` (`withoutRelationships()`) before core hydrates;
3. after hydration, sets each named association through
   `mutateRelationship(… Mode::Replace, flush: false)` so the persister resolves
   the linkage ids to managed references / stored objects;
4. lets the single `create()`/`update()` own the commit (ADR 0018).

A whole-resource write that embeds relationships is exercised in
[`RelationshipMutationTest`](../examples/music-catalog-symfony/tests/RelationshipMutationTest.php).

### Relationship endpoints

The related/relationship read arms (`fetchRelated()`, `fetchRelationship()`) and
the three mutation arms all share a parent-load + relation-resolve shape, then
render or mutate. Per-relation endpoint exposure, polymorphic rendering, and the
mutability/cardinality guards are owned by [relationships](relationships.md); the
storage-correct apply rides `mutateRelationship()`, documented above.

### Customizing the handler

There is no per-type handler code to write. Customization composes through:

- a **higher-priority provider/persister** (override the data layer for one type);
- a **per-type serializer/hydrator override** (override the wire shape — see
  [custom serializers/hydrators](custom-serializers-hydrators.md));
- **decorating this handler** (the single global handler is overridable by
  decoration — see [handler decoration](custom-serializers-hydrators.md)).

## `TypeMetadataResolver` — tolerating a bare pair

The handler resolves each type's declarative metadata (its resource, its declared
relations) through the
[`TypeMetadataResolver`](../src/Server/TypeMetadataResolver.php) seam, not directly
off the `Server`. This is the capstone seam that lets the engine stay generic over
**both** a full `AbstractResource` and a **bare serializer/hydrator pair** — the
resource-less [standalone capabilities](capability-composition.md#the-three-standalone-attributes)
registered with just a serializer and hydrator and no field inventory — without
per-type branching (ADR 0021).

```php
public function resourceFor(Server $server, string $type): ?AbstractResource
{
    try {
        return $server->resourceFor($type);
    } catch (NoResourceRegistered) {
        return null;
    }
}
```

`resourceFor()` returns `null` for a bare pair. So wherever the handler needs a
field inventory — filters, sorts, pagination, validation — a `null` resource means
those steps are simply skipped on that path: a bare-pair collection fetch passes
empty `$filters`/`$sorts` and no resource-level paginator, and writes through a
bare pair are not validated (a real gap a capability-composition user must know —
see [validation](validation.md)). `relationNamed()` sources relations
resource-first then from the type-keyed
[`RelationsRegistry`](../src/Server/RelationsRegistry.php), so a resource-less type
that declared [standalone relations](capability-composition.md) resolves the same
way as a resource.

## Where the data lives

The bundle ships two implementations of these SPIs, both in `src/` so they are
documented, copyable examples:

| Adapter | Provider / persister | Default priority | Covered in |
| --- | --- | --- | --- |
| Doctrine ORM | `DoctrineDataProvider` / `DoctrineDataPersister` | `-128` (fallback) | [doctrine](doctrine.md) |
| In-memory | `InMemoryDataProvider` / `InMemoryDataPersister` | (registered per type) | [custom providers](custom-data-providers.md) |

The Doctrine adapter is the zero-config default for any `#[AsJsonApiResource(entity:
…)]`-mapped type. The in-memory provider is a reusable worked example and a
conformance witness. Anything else — a static list, a reference dataset, a remote
API, a polymorphic to-many Doctrine cannot scope — is a custom provider over these
same SPIs.

## Next / See also

- [The Doctrine reference data layer](doctrine.md) — the zero-config default: the
  entity map, DQL filter/sort translation, related-collection scoping.
- [Custom providers, query extensions & the in-memory provider](custom-data-providers.md) —
  the override how-to and the in-memory provider construction.
- [Validation](validation.md) — the write path's optional Symfony Validator bridge.
- [Relationships](relationships.md) — the related/relationship endpoints the handler
  serves and `mutateRelationship()` applies.
- Core: [operations](https://github.com/haddowg/json-api/blob/main/docs/operations.md),
  [responses](https://github.com/haddowg/json-api/blob/main/docs/responses.md),
  [filters](https://github.com/haddowg/json-api/blob/main/docs/filters.md) /
  [sorts](https://github.com/haddowg/json-api/blob/main/docs/sorts.md) /
  [pagination](https://github.com/haddowg/json-api/blob/main/docs/pagination.md),
  [adapters](https://github.com/haddowg/json-api/blob/main/docs/adapters.md),
  [hydrators](https://github.com/haddowg/json-api/blob/main/docs/hydrators.md).
