# belongsToMany pivot data is read over a Doctrine association entity

A `belongsToMany` relation that declares pivot fields
(`BelongsToMany::make('tracks')->fields(['position' => 'integer', …])`) renders
those join-table values as per-member relationship meta, and filters/sorts the
related collection by them — **read-only, Doctrine-only**.

## The Doctrine fact this rests on

A plain `#[ORM\ManyToMany]` join table holds only the two foreign keys; Doctrine
**cannot** map a `position`/`addedAt` column on it. To HAVE pivot columns the join
must be modelled as an **association entity** — `PlaylistTrack { int position;
\DateTime addedAt; ManyToOne playlist; ManyToOne track }` — with the parent owning a
`OneToMany` to it and the entity a `ManyToOne` to the far type. (An earlier attempt
to DBAL-read a plain join table was built on the false premise that a plain M2M
carries extra columns; it is abandoned.) Because the pivot is a real entity, the
fetch is ONE composable DQL statement —
`SELECT resource, pivot.<field> AS pivot_<field> FROM <FarEntity> resource INNER JOIN
<AssocEntity> pivot WITH pivot.<farProp> = resource WHERE pivot.<parentProp> =
:parent [AND pivot/related filters] ORDER BY [pivot/related sorts] LIMIT/OFFSET` —
so the rendered pivot values come from the same query that scopes, filters, sorts
and paginates the page. No two-stage query, no page-shortening, correct pagination.

## What the bundle does (core stays storage-agnostic)

Core only *declares* the pivot metadata: `pivotFields()` (the existing field→type
map) and a new `through(?string)` / `pivotThrough()` override (an opaque class-string
core never interprets). The bundle interprets both:

- A **`PivotAssociationResolver`** finds the association entity from Doctrine
  metadata: scan the parent's to-many associations for a target entity that also has
  a `ManyToOne` to the far type. Exactly one match is the pivot; zero or more than
  one (ambiguous) throws a `\LogicException` naming the relation and pointing at
  `->through(PivotEntity::class)`. `pivotThrough()` short-circuits detection. This is
  the default — auto-detect with a `->through()` override.
- The reference **`DoctrineDataProvider`** implements a `PivotAwareProviderInterface`
  seam running the single DQL statement above: the far entity is the query root (so
  the shared `CriteriaApplier`/filter handler applies the **related** filter
  vocabulary unchanged), pivot keys are split out and applied on the `pivot` alias,
  and each declared field is selected as a `pivot_<field>` scalar that rides every
  hydrated row → a `farMemberId → [field => typed value]` map. The whole `ORDER BY`
  is built in **one request-ordered pass** across both aliases — a `?sort` field
  routes to `pivot.<field>` or to the related sort column on the root in the exact
  order the client requested, so `?sort=position,title` orders by the pivot key first
  and `?sort=title,position` by the related key first (the shared applier is **not**
  let append all related sorts ahead of the pivot ones, which would silently demote a
  pivot-first sort).
- Rendering rides core's existing `getMeta()` path (no core render change), which the
  transformer emits into BOTH the full resource (related endpoint) and the resource
  identifier (relationship-linkage endpoint). A bundle `PivotMetaSerializer`
  decorator wraps the related serializer and merges the member's pivot values under a
  `meta.pivot` key; the handler binds it for the related endpoint, and a
  `PivotParentSerializer` + `PivotSubstitutingResolver` bind it for the
  relationship-linkage endpoint (whose linkage the parent serializer builds).
- The pivot field keys join the recognised `filter`/`sort` vocabulary **only** on the
  Doctrine pivot relation's related endpoint (merged in `CrudOperationHandler::fetchRelated`
  alongside the related-resource + relation-scoped vocabulary). They route to the
  pivot column there; everywhere else a pivot key is unrecognised → 400.

## Boundaries

- **Doctrine-only.** Pivot needs an association entity to query; the in-memory
  provider does not implement the seam, so a pivot key 400s there and no pivot meta
  renders. A `belongsToMany` *without* `fields()` (and any `HasMany`) keeps the
  existing `fetchRelatedCollection` path unchanged on both providers.
- **Read-only.** Setting a pivot value on add (e.g. assigning `position` when adding a
  track) is out of scope.
- **One pivot row per member.** Pivot meta is a single per-member value set, not a
  list. Where the same far entity is a member more than once (duplicate membership —
  a track joined to a playlist at two positions, a legitimate association-entity use),
  the related-collection query `GROUP BY`s the far id so it returns one row per
  distinct member: pagination is correct (the total is `COUNT(DISTINCT)`, no member
  splits across pages, no inflated count) and the rendered pivot meta reflects a
  single representative membership row. A relation needing every membership rendered
  must model the membership as its own resource.

This supersedes the note in ADR 0044 that pivot/join-table columns are supported only
via a custom `FilterHandler`/`SortHandler`: for an association-entity-backed
`belongsToMany`, the framework now wires pivot render + filter + sort itself.
