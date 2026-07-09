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

**Out of scope** (a known follow-up, unchanged here): lifting the batched-include
cursor restriction so a cursor-resolved **included** relation mints a per-parent
forward cursor from the boundary row instead of paging offset page-1. That needs a
new parent-partitioned keyset push-down in the native batch layer (the current
`ROW_NUMBER`/group-limit batch is offset-only), byte-identical to the in-memory
witness — the same follow-up the Laravel side earmarks under its ADR 0006. A menu
that contains a page strategy still batches includes cleanly (the profile pins the
included page to page-1, whose `page[number]=1` resolves the page child); only a
menu resolving to cursor on an include is subject to the existing limitation.
