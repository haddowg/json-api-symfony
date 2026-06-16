# Null a to-one when a relation filter excludes its target

A relation filter that excludes the single related object of a TO-ONE relation now
renders the to-one as `null` and contributes nothing to includes — the to-one twin
of the to-many related endpoint's filtered collection. The request syntax already
ships: the same relation-scoped (`withFilters()`) + related-resource filter vocabulary
the to-many related endpoint resolves (via `RelationCriteriaFactory`) is now reachable
on the three to-one read surfaces, with no new param family. Bundle-only, **no core
change** — core already renders a null to-one as `data: null` and includes nothing for
it.

Three surfaces, all dual-provider (in-memory + Doctrine-sqlite) byte-for-byte:

1. `GET /{type}/{id}/{toOneRel}?filter[<key>]=…` — the related-resource document
   renders `data: null` when the filter excludes the target.
2. `GET /{type}/{id}/relationships/{toOneRel}?filter[<key>]=…` — null linkage when
   excluded.
3. `relatedQuery[<toOneRel>][filter][<key>]=…` (the Relationship Queries profile,
   `rQ[…]` shorthand too) on a PRIMARY request (collection or single resource) — the
   to-one linkage is nulled AND the target is omitted from `included[]` when `?include`
   named it.

**`relatedQuery` on a to-one is `[filter]` only.** A `[sort]` or `[page]` addressed to
a to-one path is a `400` (a single member has nothing to order or page). The batcher's
`validatePaths()` used to reject ANY to-one path; it now relaxes for `[filter]` only.
Core's `parseRelatedQueries()` captures only `sort`+`filter` ops and silently drops a
`[page]` op (a `RelatedQuery` carries no page field), so a `[page]`-on-to-one cannot be
seen through `getRelatedQueryPaths()`/`RelatedQuery`; the batcher therefore inspects the
RAW `getQueryParam('relatedQuery'/'rQ')` family (populated only when the profile is
negotiated) to detect a `[sort]`/`[page]` op on a to-one path and reject it. A
filter-only to-one path passes through.

**Mechanism — a new bundle `DataProvider` seam.** `relatedToOneMatches()` answers
"does this single related object satisfy these filters?" for the single-resource
surfaces (1 & 2, one object → one probe); `relatedToOneMatchesBatch()` answers it for a
whole page of parents in ONE store round-trip for the primary/include path (surface 3),
keyed by parent wire id exactly as `countRelated()`/`fetchRelatedCollectionBatch()` key.
The batch MUST NOT N+1 — a per-parent probe on the include path would be a performance
regression. The in-memory witness wraps the related object in a 1-element list and runs
the shared `CriteriaApplier` (the stated conformance witness); the Doctrine reference
runs a cheap `SELECT 1 … WHERE id = :id` probe and, for the batch, projects each
parent's to-one target id off the managed parent (no proxy init, reusing
`toOneTargetId()`/`parentWireId()`) then ONE `WHERE id IN (:targetIds) AND <filters>`
query, intersecting — reusing `DoctrineFilterHandler` so column/operator/cast semantics
match the to-many endpoint exactly. An unknown filter key `400`s in the applier exactly
as the to-many endpoint; filter VALUE validation (`FilterValueValidator`) applies as on
the to-many path.

**Linkage-nulling mechanism.** The exclusion writes `null` back onto the parent's
to-one property before render (`Accessor::set`), consistent with how
`RelationshipWindowBatcher` writes the windowed page back onto the parent for a to-many
and `RelatedIncludeBatcher` writes a to-one target onto the property — the serializer
reads linkage off the property, so a nulled property renders `data: null` and the
include drops. Because the include nulling pass overwrites a value `preloadIncludes()`
already wrote, it MUST run after `preloadIncludes()` (it does:
`applyRelationshipWindows()` is already called after `preloadIncludes()` at both call
sites). The write-back NEVER flushes — every surface is a GET read and every
persister/flush call in `CrudOperationHandler` lives in a write arm; the Doctrine
probe is read-only.

`MorphTo` (polymorphic to-one) is out of scope for this slice: it carries no single
related-resource filter vocabulary, so its filter merge has nothing to resolve. The
seam is exercised only for a monomorphic to-one.
