# Deeper fail-fast servability warm-up guards (polymorphic discrimination, Doctrine columns, pivot resolvability)

The bundle already fails the BUILD (`cache:warmup`) — not a runtime 500 or a silent
mis-render — on a missing provider/persister/Id (`ServableResourceWarmer`) and a
malformed `on()` eager chain (`EagerLoadWarmer`). We extend that fail-fast family with
three more guards that close the remaining "boots fine, breaks at request time" gaps, all
non-optional (`isOptional()` returns `false`) so the `\LogicException` aborts the deploy:

- **A5 (polymorphic getType discrimination, provider-agnostic, in `ServableResourceWarmer`).**
  A polymorphic relation resolves its per-member serializer in core's
  `AbstractRelation::resolveSerializer()` by matching each member's own `getType()` against
  the declared related types. `AbstractResource::getType()` returns `static::$type`
  unconditionally, so a candidate that does not override it becomes a silent catch-all that
  claims — and mis-serializes — its siblings' members. The guard requires every
  `AbstractResource` candidate of a *polymorphic* relation (a monomorphic relation
  short-circuits and never compares `getType`) to override `getType()`, detected by
  reflecting the declaring class of its `getType` method. A custom (non-`AbstractResource`)
  serializer owns its own `getType` and is skipped. We deliberately do NOT auto-derive
  `getType` from any entity map — that would break the shipped one-entity-multiple-types
  feature.

- **A3 (sortable/filterable column resolvability, Doctrine-only) + A7 (pivot resolvability,
  Doctrine-only), in a new `DoctrineServableWarmer`** registered only when the Doctrine
  reference adapter is wired (the `DoctrineEntityMapPass` fills its `type → entity` map and
  removes it alongside the provider/persister when no resource maps an entity). A3 asserts
  every field-derived / declared sort column and every column-targeting filter column
  resolves to a real `hasField()`/`hasAssociation()` on the entity — validating the
  *resolved* column, so a `computed()` field marked `sortable()` (whose sort column defaults
  to the field name) is caught, but the same field WITH a matching `sorts()` override
  supplying a real column passes; single-segment columns only, so a pivot-routed
  (`pivot.x`) or embedded (`a.b`) column is left to its own path. A7 runs the
  `PivotAssociationResolver`'s metadata discovery (refactored out of the parent-instance
  `resolve()` into a parent-class-free `discover()`) for every pivot `belongsToMany`, so its
  existing `\LogicException` fires at warm-up rather than the first write — only the timing
  moves.

The guards are intentionally strict with no opt-out: an unservable configuration is always
a bug, and surfacing it at build time is the whole point.
