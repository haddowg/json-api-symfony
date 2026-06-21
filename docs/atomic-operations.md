# Atomic Operations

The [JSON:API Atomic Operations extension](https://jsonapi.org/ext/atomic/) lets a
client send a **batch of write operations in one request**, applied in order, **all
or nothing**: either every operation commits, or the first failure rolls the whole
batch back. A later operation can reference a resource an earlier one created — by a
client-assigned **local id** (`lid`) — so you can create a parent and its children,
or wire up relationships, in a single transactional request.

It is **opt-in** and **off by default**. When enabled, each [server](multi-server-and-testing.md)
gains one endpoint:

```
POST /operations
```

## Enabling it

```yaml
# config/packages/json_api.yaml
json_api:
    atomic_operations:
        enabled: true        # default: false
        path: /operations    # default: /operations
```

`path` is the single literal path the endpoint is served at, per server. It must not
equal any resource's collection path (`/{uriType}`) — that would shadow the type's
`POST` Create. The [route loader](routing.md) **fails fast at boot** with a clear
error naming the colliding type if it does, so you cannot ship the shadow by accident.

## The request

The request `Content-Type` **and** `Accept` must both carry the atomic extension's
media-type parameter:

```
Content-Type: application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"
Accept: application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"
```

A request missing the `ext` on `Content-Type` is a `415`; missing it on `Accept` is a
`406`. The body carries an ordered `atomic:operations` array:

```json
{
  "atomic:operations": [
    {
      "op": "add",
      "data": {
        "type": "authors",
        "lid": "new-author",
        "attributes": { "name": "Margaret Hamilton" }
      }
    },
    {
      "op": "add",
      "data": {
        "type": "articles",
        "attributes": { "title": "Apollo guidance" },
        "relationships": {
          "author": { "data": { "type": "authors", "lid": "new-author" } }
        }
      }
    }
  ]
}
```

Each operation's `op` is `add`, `update`, or `remove`, and it targets its endpoint by:

- a **`ref`** — `{type, id}` (or `{type, lid}`), optionally with a `relationship` to
  target the relationship-linkage endpoint;
- an **`href`** — a URL matched against your JSON:API routes (the same route defaults a
  direct call resolves); or
- for a resource `add` with neither, the resource object's own `data.type`.

`op` maps to the CRUD verb: a resource `add`/`update`/`remove` is a create / `PATCH` /
delete; a relationship `add`/`update`/`remove` is an add-to / replace / remove-from.

### Local ids (`lid`)

A create may carry a `lid` — a client-assigned handle for the not-yet-created
resource. A later operation references it (in a `ref`, in relationship linkage, or in a
resource object's `relationships`); the executor resolves it to the real, store-assigned
id after the create runs. References are **backward only**: an operation that references
a `lid` no earlier operation registered is a `400` `LOCAL_ID_NOT_FOUND`, and a duplicate
`lid` is a `400` `LOCAL_ID_CONFLICT` — each located at the failing operation's pointer.

## The response

On success, `200 OK` with an `atomic:results` array — one result per operation, in
order, each a `{data?, meta?}` fragment. An operation with nothing to return (a
`remove`, or an `update` that yields no body) is the **empty result object** `{}`:

```json
{
  "atomic:results": [
    { "data": { "type": "authors", "id": "3", "attributes": { "name": "Margaret Hamilton" } } },
    { "data": { "type": "articles", "id": "9", "attributes": { "title": "Apollo guidance" } } }
  ]
}
```

The response always advertises the extension on its `Content-Type`
(`ext="https://jsonapi.org/ext/atomic"`).

!!! note "Always `200`, never `204`"
    A batch where every result is empty is still `200` with an `atomic:results` array of
    empty objects — the bundle deliberately does not return a `204`. One consistent
    success shape is simpler than a status that varies by the batch's content.

!!! tip "Try it in the example app"
    The [music-catalog example app](https://github.com/haddowg/json-api-symfony/tree/main/examples/music-catalog-symfony)
    enables atomic operations (`atomic_operations.enabled: true`), so `POST /operations`
    is live there. Every entity type is served by the one shared Doctrine persister, so a
    batch over them is fully atomic. A self-contained batch you can run as-is:

    ```json
    {
      "atomic:operations": [
        { "op": "add", "data": { "type": "playlists", "attributes": { "title": "Morning Run", "public": true } } },
        { "op": "add", "data": { "type": "playlists", "attributes": { "title": "Late Night Coding", "public": false } } }
      ]
    }
    ```

    sent with both `Content-Type` and `Accept` set to
    `application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"`, returns `200` with an
    `atomic:results` array of the two created playlists, each with its server-minted UUID.

## All or nothing

The whole batch runs inside one transaction opened on every participating
[persister](data-layer.md). The **first** operation that fails — a validation `422`, an
authorization or mutability `403`, a missing target `404`, a `lid` error `400` — rolls
back the entire batch and returns a single error document. Each error's `source.pointer`
is prefixed with the failing operation's index, e.g.
`/atomic:operations/1/data/attributes/title`. Every error document on the atomic
endpoint advertises the extension (except the `415`/`406` negotiation failures, where the
extension was never applied).

!!! warning "Atomicity needs a transactional persister"
    Every type a batch touches must be backed by a persister that implements
    `TransactionalDataPersisterInterface` (the Doctrine reference persister does). A batch
    touching a type whose persister is not transactional is **refused up front** with a
    `403` `ATOMIC_OPERATIONS_NOT_SUPPORTED`, before any write — so a partial,
    non-rolled-back batch can never occur. A type with no registered persister at all is a
    `404` `ATOMIC_TARGET_TYPE_UNKNOWN` (there is no routing step inside a batch to reject an
    unknown type first).

    The all-or-nothing guarantee is scoped to **one transactional persister per batch**.
    The default — one shared Doctrine `EntityManager` across every entity-mapped type — is
    a single persister and is fully atomic. A batch spanning two *distinct* transactional
    persisters commits them in turn; with no two-phase commit across stores, a later commit
    failure cannot undo an earlier durable one. Back a batch's types with one transactional
    persister when you need strict cross-store atomicity.

## Lifecycle hooks under a batch

The per-operation [lifecycle hooks](lifecycle-hooks.md) still fire, with one
adjustment: a batched write's **`After*` hooks are deferred** to run *after* the whole
batch commits (so they observe the durably-persisted state), where the **`Before*`**
hooks run inline (they gate the write). Because the batch is the unit of work, a
deferred `After*` hook's response replacement is inert under atomic.

An `After*` hook that **throws** is logged and ignored — the batch has already
committed durably by the time the deferred hooks run, so a throwing post-commit hook
does **not** fail the response or roll anything back. A hook with a hard post-commit
invariant must handle its own errors; it cannot abort a committed batch.

## What the atomic endpoint does *not* do

- **No `?include` / sparse `?fields`.** A result object is `{data, meta}` only — no
  compound document, no `included`, no sparse-fieldset narrowing. An `?include`/`?fields`
  query parameter on `/operations` is neither honoured nor rejected; it is simply not
  processed. (An *unrecognized* query parameter is still the endpoint's normal `400`.)
- **Per-operation handler decoration.** A [handler decorator](custom-serializers-hydrators.md)
  wraps the batch as a whole, not each sub-operation.

**Next:** [Routing →](routing.md)
