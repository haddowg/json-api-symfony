# Alias-aware CriteriaApplier replaces the hand-rolled pivot applier

The Doctrine pivot related-collection endpoint hand-rolled its own filter/sort
applier (`applyPivotCriteria`/`applyPivotFilters`/`applyPivotSorts`/`validateDefaults`/
`relatedSortFor`, ~150 lines) because the shared `CriteriaApplier` could only ever
target a query's root alias, while a pivot fetch roots the far entity at `resource`
and joins the association entity as `pivot` — so pivot filters/sorts had to be
applied on a second alias. The hand-rolled path rebuilt pivot filters as raw
equality `andWhere`s on `pivot.<col>`, which meant a pivot field could only be
filtered for equality and its value cast had to be re-applied inline.

We made the shared applier **alias-aware** instead. `CollectionCriteria` gains a
bundle-only `array<string,string> $aliasOf` map (directive key → target alias; an
absent key resolves to the root), `RelationCriteriaFactory` populates it with each
pivot key → `pivot` for the pivot endpoint, and the bundle's Doctrine handlers
implement a new bundle `AliasAwareFilterHandler`/`AliasAwareSortHandler` capability
(`applyOn(...$alias)`) so a directive can be pushed down on any alias of the one
builder. The applier stays **inert** when `aliasOf === []` — every non-pivot path and
the entire in-memory provider route to the root through the unchanged `apply()`, so
they are byte-identical; sorts keep their single composite call (the in-memory stable
multi-key sort + core ADR 0016) and only switch to the one-at-a-time, request-ordered
cross-alias pass when a non-empty map is present, reproducing the hand-rolled
`ORDER BY` exactly. Core's `FilterHandlerInterface`/`SortHandlerInterface` are
untouched (the capability is a bundle-only seam).

This is a pure consolidation: pivot **equality** filtering keeps its exact prior
behaviour, only now routed through the shared handler on the `pivot` alias rather than
the hand-rolled applier. `PivotFields::filtersFor()` still derives one equality `Where`
per pivot field (keyed by the field name, columned by its declared column) — but each
now threads the field's own value cast via `Where::deserializeUsing()`. That cast thread
is the one genuine fix the hand-rolled applier had inline and the bare shared path would
otherwise drop: the handler binds `$filter->deserialize`, which `Where::make()` leaves
null, so without it a typed pivot column (a `DateTime`/`bool`) would bind the raw request
string and silently regress (an int column survived only via DQL's type-coercing
comparison).

Pivot fields do **not** gain operator support here. Author-declared pivot operators —
reached via a `{relation}.{pivotField}` column target rather than an auto-derived
per-field operator vocabulary — are a deliberate follow-on, kept out of this slice so the
operator surface is author-declared, not invented.
