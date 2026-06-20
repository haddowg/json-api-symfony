# Eager-loading multi-hop flattened `on()` attributes

Core added the orthogonal attribute trio — plain `Str::make('x')` | flattened
`->on('path')` | computed `->computedUsing($closure)` — plus the load-not-render
declaration `DeclaresEagerLoadsInterface::eagerLoadRelationshipPaths()` (implemented by
`AbstractResource` as the dedup set of every `on()` attribute's backing relation chain).
`->on($path)` takes a `.`-separated chain of **declared to-one** relations — `'author'`
(single hop) or `'publisher.country'` (multi-hop) — and reads/writes the attribute's own
`column() ?? name()` on the **final** related model in the chain. Core only **declares**
the eager set and runs the flattened read/write itself (read flattens
`model.seg1.…segN.member`, any intermediate null short-circuits to null; write mutates the
loaded final related model in place, or 422s `RELATED_ATTRIBUTE_OWNER_MISSING` when any hop
is null — no auto-instantiate); the bundle is the **execution site** for the eager loading.

The decision: extend the existing provider-agnostic `RelatedIncludeBatcher` (the
`?include` batch, bundle ADR 0062) with an **eager-load pass** rather than build a second
loader. Before the `?include` walk, the batcher reads `eagerLoadRelationshipPaths()` off
the resolved serializer (`instanceof DeclaresEagerLoadsInterface`, so a bare/standalone
serializer with no field inventory is tolerated — it declares no eager loads) and loads
each declared chain through the same `DataProvider::fetchRelatedCollectionBatch()` seam
(**no SPI change**): the to-one fast-path (`WHERE id IN`) for each to-one segment. Each
loaded value is written back onto its parent's column via `Accessor::set`, so the flattened
read reads it off the parent with no per-row N+1.

An `on()` chain MAY be **multi-hop** (`'publisher.country'`). The eager pass folds the
declared paths into a prefix tree and walks each level **segment by segment**,
batch-loading each level across the targets the previous level loaded — the same
level-walk / fan-out the `?include` tree uses — and following each segment's relation to
the next type via `RelationInterface::relatedTypes()`. So a multi-hop chain loads in
**O(depth)** (one batched `WHERE … IN` per level, no per-row N+1 at any level), and a
shared prefix loads **once** (`author.country` and `author.city` collapse to a single
`author` load whose targets seed both branches). A polymorphic / inventory-less segment
whose next type cannot be resolved to a single registered type stops that branch lazily —
exactly as the include walk leaves it.

The seam carries these deliberate properties:

- **The eager set is excluded from `included` for free.** The eager load writes the
  related value onto the parent's column exactly as a lazy read would have materialised
  it; rendering stays gated on the transformer's `isIncludedRelationship`, which the eager
  set never touches. So eager-loading changes only the query plan, never the document — a
  hidden `on()` backing relation (at any nesting depth) is loaded but **never** rendered as
  a relationship or expanded into `included` unless *also* `?include`'d.

- **Every `on()` segment is a declared to-one — validated fail-loud at boot.** `on()`
  flattens a single scalar from a to-one chain, so every segment must be a declared
  relation and to-one. A **to-many** segment at any depth (a misuse where a collection was
  meant for `?include`) and an **unknown** segment (a typo that would silently no-op) are
  both hard, developer-facing `\LogicException`s thrown at **boot / container warm-up** by
  core's `EagerLoadValidator`, surfaced through the bundle's `EagerLoadWarmer` (a
  **non-optional** `kernel.cache_warmer`). The build therefore fails at `cache:clear` /
  deploy — never as a runtime 500 — naming the offending segment, the path, and the fix (a
  to-one chain, or `?include` for a collection). The rule bites **every segment /
  ancestor**, not just the leaf. A segment may be `hidden()` (the idiomatic internal
  association) or visible — both pass, because the chain is to-one: eager-loading a to-one
  never flips its linkage rendering, so there is **no windowed-to-many interaction and no
  rendering contradiction** to guard against (the earlier "visible lazy to-many"
  contradiction rule is moot and removed).

- **The eager set bypasses the client-include safeguards.** It is author-declared and
  trusted, so the depth cap / allowed-paths whitelist / `cannotBeIncluded` (bundle ADR
  0037) — which gate untrusted *client* input — are not consulted for it. The pass runs
  before, and independently of, the safeguarded `?include` walk.

- **An overlap with `?include` (or a sibling eager path) loads once.** A per-level
  "already loaded" guard keyed by relation name marks each eager-loaded relation, and holds
  **across levels**; the include walk (and a sibling nested branch) reuses the value
  already written onto the column (reading the loaded targets straight off the parents to
  seed the next nested level) instead of re-running the batch.

Targets resolve against the **hidden-inclusive** declared-relation set via a new
`TypeMetadataResolver::relationNamedIncludingHidden()` — `TypeMetadataResolver::relationsFor()`
and core's `AbstractResource::relationNamed()` both filter hidden out, but an `on()`
attribute's backing relation is idiomatically `hidden()` (the internal association), so it
must still be found and loaded. The pass resolves the serializer through the **default**
server (`hasSerializerFor` guard) and short-circuits a type registered only on a
non-default server (it renders lazily) — preserving the multi-server boundary the original
empty-relation early-return covered.

Because core hydrates `on()` attributes **after** relationships, the bundle handler must
apply a write body's embedded relationships **before** it drives core's hydrator — the
handler strips relationships from the body and applies them through the persister seam
(ADR 0018), so that step is reordered to run before `hydrate()`. The hydrate body stays
stripped, so core's `hydrateRelationships()` is still a no-op (no scalar-id assignment),
but the flattened `on()` pass now reads a related model **associated in the same request
body** off the parent. Without this, a same-body write would resolve the owner from the
*previously* associated (or absent) related model — `422 RELATED_ATTRIBUTE_OWNER_MISSING`
on a create that supplies the association, or a wrong-owner write on an update that
switches it. The mutability guard moves with the apply step; it is evaluated against the
loaded (pre-hydration) parent, the correct "existing" state for an authz decision.

Dual-provider conformance (`FlattenConformanceTestCase` + the in-memory / Doctrine
subclasses, a `books`-over-`authors`/`countries` fixture) asserts on both providers: a
single-hop flattened `authorName` read identical (and null over a null author), a
**multi-hop** `authorCountry` (`on('author.country')`) read flattening `author.country.name`
(and null when the first hop is absent), a computed `display` read-only (a write of it is
ignored, the value derives from the closure), the hidden `on()` backing `author` (and the
hidden second-hop `country`) never rendered as a relationship or in `included` (while the
**visible** backing relation `editor` does render its linkage), a flattened `authorName`
PATCH mutating the related author in place AND a multi-hop `authorCountry` PATCH mutating
the final related country in place (asserted via re-fetch — Doctrine UoW auto-persist of the
dirty loaded entity, in-memory shared reference), an absent-hop flattened write →
`422 RELATED_ATTRIBUTE_OWNER_MISSING` at the attribute pointer (single and multi-hop), and
the **same-body** witnesses over the visible `editor`/`editorName` pair — a create that
associates `editor` and sets `editorName` in one document lands the value on that editor
(`201`), an update that switches `editor` lands the value on the **new** editor and leaves
the old one untouched. A Doctrine query-budget witness proves the per-row N+1 from the
flattened read collapses to ONE batched `WHERE id IN` load **per author-backed relation**
(`author` and `editor`) AND that the **multi-hop** `author.country` second hop collapses to
ONE `WHERE id IN` country load (O(depth), no per-row second-hop SELECT), with the rendered
document byte-identical to the un-batched one.

A separate dual-provider `EagerLoadValidationConformanceTestCase` (in-memory + Doctrine
kernels) is the **fail-loud** witness: a resource flattening over a **to-many segment** —
as the leaf (`on('tags')`) AND as an ancestor (`on('tags.region')`) — throws a
`\LogicException` at warm-up on both providers with the not-flattenable message; an
**unknown segment** (`on('nope')`) throws; and a valid multi-hop to-one chain
(`on('region.region')`, mixing a hidden and a visible hop) boots clean (accepted).

`with()` / `alwaysLoadRelationships()` is **dropped**: `on()` flattening (now multi-hop)
and `?include` cover the real cases, so a standalone load-not-render pin is no longer a
distinct primitive in core or the bundle.
