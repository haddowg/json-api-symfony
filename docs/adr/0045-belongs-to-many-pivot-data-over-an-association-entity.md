# `belongsToMany` pivot data is served over a Doctrine association entity, under `meta.pivot`

A plain Doctrine `ManyToMany` join table cannot carry extra columns, so pivot fields require the join to be modelled as an association entity, which the bundle discovers from the mapping (or `->through()` names). Pivot values are rendered, written and validated at each linkage member's `meta.pivot`, matching the OpenAPI schema, and are read in the same query that scopes, filters, sorts and pages the related collection; a write is an association-entity diff (update a row in place, create one, remove those that left on replace). Pivot is Doctrine-only: the in-memory provider ignores pivot meta and does not know pivot keys.

## Consequences

- One pivot row per member: duplicate membership is grouped to one representative row so pages and counts stay correct, and a relation needing every membership models it as its own resource.
- Pivot filters are author-declared in `withFilters()` with a `pivot.`-prefixed column; pivot sorts derive from the declared pivot fields.
- Pivot values are cast to wire form in the provider, so the operation's output timezone reaches them through a request-scoped holder rather than a widened provider signature.
- The pivot serializers decorate the related and parent serializers, so each must forward every optional serializer interface (counting, strict fieldsets, self links, declared links); a decorator that misses one silently drops that feature.
