# A Doctrine filter self-applies via `AppliesToQueryBuilder`

- **Status:** accepted

A Doctrine-only filter can carry its own query fragment — a named query-builder /
repository method, a `Criteria` applied with `addCriteria`, or raw DQL — by implementing
`AppliesToQueryBuilder::applyToQueryBuilder(QueryBuilder, mixed, string)`. The
`DoctrineFilterHandler` consults it **before** the arm registry (the built-ins still win),
so the filter runs with **no** `DoctrineFilterArmInterface` service registered.

**Why.** It is the self-applying twin of the arm seam — where an arm is a registered
service keyed on a filter's class, this puts the application on the filter value object
itself, so a one-off, dependency-free custom filter is fully defined by its own VO. It is
the *execution* counterpart of the `NativeConstraints` carrier (ADR 0108) for validation:
paired with core's `DescribesQueryParameter` for a non-scalar `filter[…]` parameter shape,
a filter becomes wholly self-contained — value schema, OpenAPI shape, and execution — in
one class. Reach for a `DoctrineFilterArmInterface` arm instead when the application needs
injected services (a `Security`, a repository).

## Consequences

`AppliesToQueryBuilder` lives in the Doctrine namespace and runs only on the Doctrine
provider — a filter that implements it is not portable, so the same `filter[…]` key is
undeclared on the in-memory provider and a request there is a clean `400` (the
unrecognised-filter boundary), never a silent non-match. A filter that must run on both
providers ships a portable `FilterInterface` plus an arm per store instead. The handler
gains one arm before its `applyArm` fallback; no new service or tag.
