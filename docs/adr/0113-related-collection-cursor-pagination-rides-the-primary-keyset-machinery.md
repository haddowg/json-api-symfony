# Related-collection cursor pagination rides the primary-collection keyset machinery

- **Status:** accepted

`GET /{type}/{id}/{rel}` on a to-many relation that resolves a `CursorPaginator`
(the relation's own `paginate()`, else the related resource's `pagination()`,
else the server default) now pages by keyset on both providers — with **no new
pagination machinery and no core change**. The integration is exactly two
mirrored narrows on the existing ADR 0063 seams:

- **Provider** — each provider's `fetchRelatedCollection` adds the same
  "`$criteria->window instanceof CursorWindow` → `runCursor(...)`" branch its
  `fetchCollection` already has. The keyset execution is parent-agnostic by
  construction: on Doctrine the builder is already scoped to the parent (the
  `RelationScope` inverse-FK fast-path or IN-subquery), so the parent predicate
  simply rides the `WHERE` alongside the filters while the keyset owns the
  order; in memory the members were already read off the parent, so only the
  source array differs.
- **Handler** — `CrudOperationHandler::fetchRelated`'s to-many tail narrows on
  `CursorCollectionResult` and renders through
  `CursorPaginator::fromBoundaries` → `RelatedResponse::fromPage`, the related
  twin of the primary collection's cursor branch. Core's shared page rendering
  (`AppliesPaginationTrait`) then scopes the cursor links to the related URL,
  omits `last`, renders the count-free `meta.page`
  (`perPage`/`from`/`to`/`hasMore`), and advertises the cursor-pagination
  profile iff the server registers it — identically to the primary path.

**Why.** The keyset execution (column resolution, staleness, the NULL=largest
order, token minting) was deliberately built parent-agnostic in ADR 0063; a
related collection is the same filtered+ordered set under one extra membership
predicate, so anything more than the two narrows would duplicate machinery. The
dual-provider `RelatedCursorConformanceTestCase` (the `cursorShelves` →
`widgets` fixtures, the relation declaring its own `CursorPaginator`) asserts
the walk stays inside the parent scope and matches the primary reference orders.

**Out of scope** (unchanged, known follow-ups): the pivot related path
(`fetchRelatedPivotCollection` still runs offset windows only), windowing the
relationship-linkage `GET` by cursor, and hoisting the byte-identical Keyset
machinery (`KeysetResolver`/`KeysetColumn`/`CursorTokenMinter`/`InMemoryKeyset`)
into core for the sibling packages to share.
