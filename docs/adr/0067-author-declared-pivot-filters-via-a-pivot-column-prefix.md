# Author-declared pivot filters via a `pivot.` column prefix

A `belongsToMany` pivot relation used to auto-derive an equality `filter[<field>]`
per declared pivot field, so declaring `Integer::make('position')` silently made
`filter[position]=2` narrow the related collection. That coupled two concerns
(rendering the pivot value and exposing a filter), exposed only equality (no
operator, no `WhereIn`, no null check), and gave the author no control over the
filter key, operator or which fields are filterable. We replaced it with
**author-declared** pivot filters: an app declares every pivot filter explicitly in
the relation's `withFilters()` as a NORMAL core filter (`Where` + operators,
`WhereIn`/`WhereNotIn`, `WhereNull`/`WhereNotNull` — no marker class, no `Pivot::`
wrapper) whose **column is `pivot.`-prefixed**. A filter whose column starts with
`pivot.` targets the pivot join; the prefix is stripped to the real association-entity
column and the filter routed to the `pivot` alias. A filter with no prefix targets the
related/root entity. The filter KEY is independent of the column, so a pivot filter
can be named anything (`addedAfter` over `pivot.addedAt`).

```php
BelongsToMany::make('tracks')->type('tracks')
    ->fields(Integer::make('position'), DateTime::make('addedAt')->readOnly())
    ->withFilters(
        Where::make('position', 'pivot.position'),          // filter[position]=2
        Where::make('positionGte', 'pivot.position', '>='),  // an operator
        WhereIn::make('positionIn', 'pivot.position'),       // a set
        Where::make('addedAfter', 'pivot.addedAt', '>'),     // a typed-date column
    );
```

The value **cast** auto-resolves from the declared pivot field whose `column()`
(defaulting to `name()`) equals the STRIPPED column — the same `Integer`→int /
`DateTime`→ISO-8601 cast the auto-derivation applied — so a typed pivot column binds a
typed value. The resolution keys on the stripped column (not the wire key), so a
renamed filter still casts. An explicit `->deserializeUsing()` on the authored filter
still wins. `WhereNull`/`WhereNotNull` carry no value, so no cast is resolved.

The strip lives in two places that already diverge from the byte-identical root path,
so the immutable core filter VOs stay untouched (no clone, no per-type rebuild):
`RelationCriteriaFactory` re-sources the pivot `aliasOf` map and the cast from the
relation's own `pivot.`-columned filters, and the alias-aware
`DoctrineFilterHandler::applyOn` strips a single leading `pivot.` from the column when
(and only when) applying on the `pivot` alias — so `path()` builds `pivot.position`,
never `pivot.pivot.position`. Only the leading segment is stripped, so an embeddable
pivot column (`pivot.meta.x`) yields `meta.x`. The root path is byte-identical.

The **in-memory boundary is preserved** by the `includePivotFields` gate the factory
already had. The pivot filters now live in `relation->filters()`, which the factory
merges on EVERY path (the in-memory related endpoint, the include-window batcher, the
`?withCount` count batcher), so when `includePivotFields` is false the factory STRIPS
every `pivot.`-columned filter before merging. In-memory the requested pivot key is
then undeclared → `400` (the boundary witness is unchanged); and the include/count
Doctrine paths (empty `aliasOf`) never route a `pivot.`-column to the root alias (a
`root.pivot.position` field path would be a hard error).

This is **Family B** (relation-scoped). The `pivot.` literal keyword does not collide
with a relation-name target — a different keyword in a different declaration context.

**Sorts are unchanged.** Only the filter auto-derivation is dropped; pivot SORTS still
auto-derive (`PivotFields::sortsFor`), so `?sort=position` stays zero-config — a
deliberate filter/sort asymmetry (a pivot SORT is a single well-defined ordering;
a pivot FILTER spans operators/sets/null the author must choose).

**BEHAVIOUR CHANGE.** Dropping the auto-derivation removes the zero-config
`filter[<field>]` an app got for free from a declared pivot field; it must now declare
each pivot filter (`Where::make('position', 'pivot.position')`). This is breaking and
bumps the `0.x` minor. The cast on `WhereIn`/`WhereNotIn` is a known weaker guarantee:
the handler binds the split list raw (it reads a deserializer only on the scalar
`Where` arm), so a typed-element pivot `WhereIn` (a `DateTime`/`bool` set) binds
uncast — harmless for an int column (DQL coerces). Thread an explicit element shape if
a typed-element `WhereIn` is needed.
