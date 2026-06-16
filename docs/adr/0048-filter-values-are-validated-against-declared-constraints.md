# Filter values are validated against declared value constraints

A filter can declare **value constraints** (core ADR 0055: `FilterInterface::constraints()`,
the `numeric()` / `integer()` / `uuid()` / `boolean()` / `pattern()` / `constrain()`
builders on a value-carrying filter). The bundle validates a client-supplied
`filter[<key>]` value against those constraints **before the filter reaches the
data provider**, so a mistyped value — `filter[id]=banana` on an integer column —
is a deliberate `400` with `source.parameter` on `filter[<key>]` (core's
`FilterValueInvalid`) rather than the provider's unhelpful default for a
type-mismatched value: a silent non-match in memory and on a loosely-typed
database (sqlite), or — on a strict driver such as Postgres — a PDO `500`. Greg's
decision is
**declared** constraints, author-chosen per filter — not auto-inferred from the
column — so the constraint is an explicit contract, the same way attribute
constraints are.

It is a **`400`, deliberately not a `422`**: a bad query *parameter* (located by
`source.parameter`), not a document *semantic* error (located by `source.pointer`).
The kernel.exception listener already owns core's typed exceptions on a JSON:API
route and renders `FilterValueInvalid`'s native `getErrors()` (one `400` per
violation) without remapping status.

**Implementation.** A new `FilterValueValidator` is the filter-value twin of the
`ResourceValidator`: it reuses the **same `ConstraintTranslator`** that gives
attribute constraints teeth, so the filter shortcuts (which append the existing
core constraint vocabulary — `Pattern`, `UuidFormat`, …) need no new translator
case. The `CrudOperationHandler` invokes it where it builds the `CollectionCriteria`
— on **both** the primary collection path and the related-collection path (so a
relation-scoped or related-resource constrained filter is covered too, ADR 0044) —
passing the **raw** requested `filter` map, *before* the provider's `CriteriaApplier`
folds in `FilterDefaults`. So only **client-supplied** values are validated, never a
filter's author-set `default()` (a server-trusted value). A set value (`WhereIn`,
`WhereIdIn`, …) is split — array members, or the delimited string per `delimiter()`
— and each scalar member is validated individually, so a per-member rule like
`integer()` applies to every id in `filter[id]=1,banana,3`. A filter with no declared
constraints takes no validation path and behaves exactly as before.

**Optionality.** Like the validator bridge, the filter-value validation is wired
only when `symfony/validator` is installed (the `FilterValueValidator` is injected
`nullOnInvalid`). Absent it a constrained filter is inert — its constraints are
metadata core never executes — matching how a resource's attribute constraints
degrade without the bridge. A filter that declares no constraints is unaffected
regardless.

**Consequences.** The validation is **pre-provider**, on the value, so it is
provider-agnostic: a mistyped value is a `400` on both the in-memory and the
Doctrine providers, exercised by the dual-provider
`FilterValueConstraintConformanceTestCase`. The suite asserts the `400` and that
the bad value never reaches the query; the avoided `500` is strict-driver-specific
(the conformance Doctrine kernel runs on loosely-typed sqlite, whose unvalidated
baseline is a silent non-match, not a `500`).
