# Client-selectable pagination carries a page schema and resolves the paginator up front

- **Status:** accepted

Core added a `MultiPaginator` (a `PaginatorInterface` composing several strategies
a client selects per request with `page[kind]`), and with it moved the OpenAPI
`page[…]` projection onto the paginator itself: `PaginatorInterface` now exposes
`kind()`, `describePageSchema()` and `resolve()`, and the projector emits the whole
`page` family as one `deepObject` parameter carrying that schema (a `oneOf` menu for
a `MultiPaginator`). The bundle integration is two mirrored changes:

- **Metadata** — `MetadataSource` no longer discriminates a `PaginatorKind`; the
  retired `PaginatorKindResolver` is deleted and `TypeMetadata`/`RelationMetadata`
  now carry the resolved paginator's `describePageSchema()` (`null` when
  unpaginated). The projector reads that schema directly, so a custom paginator —
  or a menu — documents its real `page[…]` keys with no bundle-side switch.
- **Handler** — `CrudOperationHandler` calls `$paginator->resolve($request)` once,
  up front, at each of its four paginator-binding sites (primary collection, the
  related collection, the pivot-related collection, and the relationship-linkage
  collection), **before** every `instanceof CursorPaginator` render/count branch.
  A single strategy resolves to itself (unchanged behaviour, proven by the whole
  suite); a `MultiPaginator` resolves to the concrete child the request selects, so
  a wrapper is never mistaken for a count-based strategy.

**Why.** Selection is a core concern (the discriminator/unique-key/default rules
live in `MultiPaginator::resolve()`), so the bundle's only job is to feed the page
schema into the metadata and resolve the wrapper before the render branches — no
new bundle machinery. The dual-provider `CursorConformanceTestCase` witnesses it:
`cursorWidgets` now offers a page+cursor menu (default cursor), so the existing
keyset suite proves the menu is transparent while new cases prove `page[kind]=page`
and the page-unique `page[number]` select the count-based strategy, a shared
`page[size]` falls back to the cursor default, and an unknown kind is a `400`.

**Cursor on a batched include (the companion lift).** A cursor-resolved **included**
relation now renders a first cursor page per parent rather than throwing. An include
carries no cursor token (the Relationship Queries profile pins the included page to
page 1), so the window is a **boundaryless** `CursorWindow` — a first page is just
the first N rows under the keyset sort + id tiebreak, which is what the existing
per-parent related-collection cursor fetch already computes. So `DoctrineDataProvider::
fetchRelatedCollectionBatch` routes a `CursorWindow` through the same per-parent
`fetchRelatedCollection` keyset path the related endpoint uses (each parent's
forward cursor minted from its boundary row via the shared `CursorTokenMinter`), and
`RelationshipWindowBatcher::paginationFor` renders a `CursorBasedPage` through
`CursorPaginator::fromBoundaries` — `next` carries the minted `page[after]`,
`prev`/`last` are omitted. The in-memory witness already windowed each parent through
that same path, so no new keyset machinery was needed and the two providers are
byte-identical (`CursorIncludeBatchConformanceTestCase`).

**Profile advertisement.** A cursor-resolved include activates the cursor-pagination
profile off its per-parent page, so the batcher surfaces those pages' profiles
(`WindowedRelationshipPagination::activatedProfiles()`) and the handler advertises
them on the document via core's `withActivatedProfiles()` — so a cursor include is
advertised even when the primary collection is page-based (proven end-to-end in
core's applied-profile suite and witnessed against a registered profile in the
Laravel twin).
