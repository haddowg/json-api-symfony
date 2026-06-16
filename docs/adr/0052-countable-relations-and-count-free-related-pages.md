# Countable relations expose meta.total; non-countable related pages are count-free

A to-many relation can be declared `countable()` (core ADR 0057, default false).
Counting is then exposed two ways, both keyed on the `total` meta key (the same key
the count-based pages already emit, so the relationship-object total and the
endpoint pagination total are one semantic):

- **`?withCount=rel1,rel2`** — a flat primary-request query parameter naming
  relationships (like `?include`) — activates `meta.total` on the named
  relationship object when the parent is rendered (a single resource, every parent
  of a collection, and a related-collection member). Core validates `?withCount` up
  front against the primary serializer's countable set (a non-countable name, a
  to-one, or an unknown name `400`s with `source.parameter: withCount`); the bundle
  does **not** re-validate. The relationship-object total is gated by both
  `isCountable()` **and** being named in `?withCount`.
- **The related-collection endpoint** (`GET /{type}/{id}/{rel}`) is gated by
  `isCountable()` **alone** (independent of `?withCount` — the endpoint total is a
  pagination feature). A countable relation's endpoint computes the total and emits
  `meta.page.total` + a `last` link as before; a **non-countable** relation's
  endpoint paginates **count-free** — no `COUNT` runs, no `total`, no `last`; a
  further page is signalled by `next` (a `limit+1` probe). This is the "partial
  pagination" mechanism for relationships, and it **changes prior behaviour**: every
  related-collection used to emit a total, so a relation whose endpoint should keep
  one is now marked `countable()`. The gate is **universal**: the same `isCountable()`
  check threads through the pivot (`belongsToMany`) endpoint too — a non-countable
  pivot relation paginates count-free over its association entity (`limit+1` probe, no
  `countPivot`), exactly as the plain path does.

**The count value is storage-specific, so it rides a count-only batch SPI seam.**
`DataProviderInterface::countRelated(type, parents, relation, request): array<wireId, int>`
counts a relation across a whole page of parents — one grouped, pushed-down query,
never N. The Doctrine provider runs `SELECT parentId, COUNT(related) … WHERE
parentId IN (:pageIds) GROUP BY parentId` reusing the related-collection scoping.
A pivot (`belongsToMany`) relation counts **distinct far members**
(`COUNT(DISTINCT pivot.<farProperty>)`) over the association entity, grouped on the
parent FK — *not* association rows: the related-collection endpoint groups one row
per distinct far member and renders deduped linkage, so the `?withCount`
relationship-object total and the endpoint pagination total must report the same
number for the same relation/parent (the one consistent `total` semantic; duplicate
membership — a track at two positions — counts **once**). A polymorphic to-many
throws, the same boundary as `fetchRelatedCollection`. The in-memory witness counts
the related set read off each parent (and **supports** a polymorphic to-many — the
mixed members are still one iterable). A `RelationCountBatcher` (mirroring the `IncludePreloader`'s
batch-by-page architecture) runs once over the fetched page, asks the provider for
one grouped count per `?withCount`-named countable relation, and builds a
`RelationshipCountInterface` (`BatchedRelationshipCount`, keyed by parent object
identity) that the handler swaps into the memoized `Server`'s count seam through a
stable `RequestScopedRelationshipCount` holder — mirroring how the Doctrine
load-state predicate is threaded. Core then renders `meta.total` from the seam in
`AbstractRelation::buildToMany()` (and `MorphToMany::buildRelationship()`); core
never computes a count. The holder is a singleton, but its render is gated on the
current request naming `?withCount`, and the read arms re-set/clear it per read; the
write/linkage arms do not, so the holder implements `ResetInterface`
(auto-tagged `kernel.reset`) and the container clears it between worker messages — a
prior `?withCount` read's counts never leak into a later render in a long-lived
container.

**Count-free pages flow through `CollectionResult`.** `fetchRelatedCollection`
threads `relation->isCountable()`: when false it paginates via the core paginator's
`paginateWithoutCount()` (count-free), returning a `CollectionResult` with a `null`
total, `windowed: true`, and a `hasMore` flag (from the `limit+1` probe); the
handler reads those to build the count-free page. A countable relation returns the
counted total as before. Cursor pagination stays count-free in itself, but a
countable relation it backs still gets `meta.total` where asked — the author opted
into the cost by declaring `countable()`.
