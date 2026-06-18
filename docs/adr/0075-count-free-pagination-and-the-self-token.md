# Count-free pagination by default; one count fanned to `meta.total` + `meta.page.total`

**Status:** accepted.

G21 flips pagination to **count-free by default**: a paginated collection windows
without running the expensive `COUNT` unless an author opts in (`PagePaginator::make()->withCount()`)
or a client asks (`?withCount=_self_` under the negotiated **Countable** profile,
on a `countable()` resource/relation). Consumed from core's count-free design
(core `PaginatorInterface::wantsCount()` / `withCount()`, the resource-level
`countable()`, the `pagination(?PaginatorInterface $serverDefault)` single-source-of-truth
signature, `AbstractRelation::withoutPagination()`, the `_self_` token, and the
Countable profile rename).

## What the bundle does

- **`ServerFactory`** passes the resolved server-default paginator into a resource's
  `pagination($serverDefault)` (verbatim — a `null` return now means *fetch-all*),
  and registers the renamed `CountableProfile`.
- **`CrudOperationHandler`** computes the COUNT decision **once** —
  `wantsCount = paginator->wantsCount() || request->countsRelationship('_self_')`
  (excluding the inherently count-free cursor) — and carries it to the providers on a
  new `CollectionCriteria::$wantsCount`. The single resolved total is **fanned to both
  slots from one count**: the universal top-level `meta.total` (and, when paginated,
  `meta.page.total` inside the count-based page). A **fetch-all** collection (no
  paginator) renders `meta.total` unconditionally from the materialised size (free, no
  query). A **count-free** page renders no total, its `next` driven by the providers'
  window+1 `hasMore` probe.
- **Providers** (Doctrine + in-memory) take `countable: $criteria->wantsCount` on the
  primary and related fetches, reaching the already-built count-free branch of the
  shared `WindowExecutor` (the window+1 probe). The `RelationshipWindowBatcher` /
  windowed-include batch sets `wantsCount: $relation->paginator->wantsCount() ||
  $request->countsRelationship($name)` — so the §6d included-relationship pagination is
  **count-free by default and counted only when the pagination counts** (the relation's
  `withCount()` paginator or a client `?withCount`), the same rule as the primary and
  related collections. This **supersedes** the slice-1 "a `countable()` relation always
  counts on include" of bundle ADR 0053.
- **`RelationCriteriaFactory::paginatorFor()`** composes the fallback bottom-up
  (`related resource → server default`) and feeds it into `relation->pagination($fallback)`,
  so `withoutPagination()` can return a real `null`.

## Consequences

- **Breaking (accepted, pre-1.0):** an unqualified paged request now renders count-free
  — no `meta.page.total`, no `links.last`; `next` is driven by `hasMore`. Counted
  output requires `withCount()` or `?withCount=_self_` under `countable()`.
- A top-level `meta.total` now appears on every counted and every fetch-all collection
  (the universal cardinality slot).
- The profile is renamed **Relationship Counts → Countable** (new URI slug
  `/profiles/countable/`); the `?withCount` param name and the `withCount` keyword are
  unchanged.
- **The related-endpoint `?withCount=_self_` count (design §6b) is relation-aware.** The
  related endpoint (`GET /{type}/{id}/{rel}`) renders through core's `CollectionDocument`,
  whose `validateCountedRelationships()` gate validates `_self_`. By default that gate
  keys on the **primary serializer's** resource-level `countable()` — which on a related
  endpoint is the *related-type resource*, not the relation. So core now accepts an
  optional `countableSelfOverride` on the document transformation (core ADR 0068): a
  related render carries the owning **relation's** `countable()` through
  `RelatedResponse::fromCollection()/fromPage($selfCountable)`, and the gate keys on it —
  so `_self_` on a countable *relation*'s collection is counted even when the related-type
  resource is not itself `countable()`. The handler passes `$relation->isCountable()`.
  All §6 paths — primary `?withCount=_self_`, the count-free default, fetch-all
  `meta.total`, `withCount()`-always, and the related-endpoint `_self_` count — work
  end-to-end on both providers.
