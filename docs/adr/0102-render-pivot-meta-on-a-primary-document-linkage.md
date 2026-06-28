# Render pivot `meta.pivot` on a primary-resource document's linkage

A `belongsToMany` relation's per-member pivot values already rendered as identifier
`meta.pivot` on the related (`GET /{type}/{id}/{rel}`) and relationship-linkage
(`GET /{type}/{id}/relationships/{rel}`) endpoints, but **not** in a primary-resource
document's relationships block (`GET /playlists/1?include=orderedTracks`) — a real
gap, since the OpenAPI projection already types `meta.pivot` on the relationship
component used there, so the runtime was out of spec. We close it **bundle-only**
(pivot rides core's existing `getMeta()` path, no core change): the read handler, in
both the fetch-one and collection branches, determines the type's `belongsToMany`
relations whose linkage data renders (included at the primary level, or `withData()`)
and whose provider `supportsPivot()`, fetches a **batched per-parent pivot map** over
the rendered page in one statement per relation (no N+1), and wraps the parent
serializer in a `PivotLinkageParentSerializer` that rebinds each such relation's
linkage to a `PivotMetaSerializer` over that parent's slice — reusing the same
`PivotSubstitutingResolver`/`PivotMetaSerializer` machinery the relationship endpoint
uses (both pivot parent decorators now share an `AbstractPivotParentSerializer` base
that transparently forwards every optional serializer-render interface — counting,
strict-fieldset, self-link — so the decoration cannot silently break those features).

The batched Doctrine query (`fetchRelatedPivotMapBatch`) projects the parent FK, the
far FK and the pivot columns as **scalars** grouped by `(parentId, farId)` — it does
not hydrate the far entity, because an object result would dedup a far member shared
across parents under Doctrine's identity map and lose one parent's row. Keying the map
by member id means it composes with any linkage windowing/filtering for free. Only the
Doctrine provider implements the seam; the in-memory provider stays the documented
pivot boundary (no pivot, even on a primary document).

The batched map's **outer** key (the parent wire id) is keyed by the **served**
parent type's id encoder — threaded into `fetchRelatedPivotMapBatch` from the handler,
not reverse-resolved from the entity class. One entity may back several types with
different encoders; a reverse-lookup picks the first-registered type's encoder and so
diverges from the parent serializer's `getId()` for any other served type, silently
dropping pivot on the primary linkage. Threading the served type makes the outer key
identical to `getId()` by construction.

## Consequences

- The wrap is gated on `included OR !emitsDataOnlyWhenLoaded()` (the same rendered-data
  gate the include-windowing path uses, ADR 0086) — a deliberate, declared boundary,
  **not** a post-hoc check of whether the linkage happened to render. A lazy,
  not-included pivot relation whose linkage nonetheless renders (e.g. via an eager
  `extractUsing`) carries no pivot; opt it in with `withData()` or `?include`.
- Pivot rides `getMeta()`, which renders into both a resource identifier and a full
  resource, so a pivot member expanded into a compound document's `included` carries
  the same `meta.pivot` as its linkage identifier — consistent with the related
  endpoint, which already renders pivot on the full far resource the same way.
- The decorator rebinds a relation's linkage **only when the inner serializer already
  rendered it** — a per-request conditionally-hidden relation (`hidden(fn …)`) that
  core excludes via `isHiddenFor()` is not resurrected onto the relationships block,
  even though the unconditionally-hidden-only selection step would otherwise select it.
  The decorator respects the inner serializer's per-request hidden decision.
