# An update validates the merged resource state, not the partial body

A `PATCH` carries a **partial** resource: only the members the client wants to
change. Before this decision the validator bridge saw only that incoming partial,
so a cross-field or conditional constraint that depends on a sibling the partial
did not re-send (`expiresAt` must be after `publishedAt`; a `when()` rule keyed off
another field) evaluated against a partial picture — it either skipped (the sibling
was absent) or wrongly failed (the sibling read as null). The existing entity was
already loaded in the handler (the update arm fetches it to hydrate onto) but never
reached the validator.

**Decision (HALF A — attributes).** The handler passes the already-loaded existing
domain object into `ResourceValidator::validate(AbstractResource $resource,
JsonApiRequestInterface $request, bool $creating, ?object $existingObject = null)`.
On an update (`$creating === false`) with a non-null existing object, the validator
resolves the stored resource's **wire-form** attribute map through the resource's
public `getAttributes()` closures (the same representation a read renders) and
**merges** it under the incoming partial (`array_merge(stored, incoming)`): an
incoming key overrides per key, a key absent from the partial keeps its stored
value, and an incoming explicit `null` still overrides because the partial is merged
last. That **merged** map is what both the per-field `Collection` and the cross-field
`compareError` loop validate. On create there is no existing object, so the incoming
document is validated as before. This is **bundle-only** — no core change; the merge
rides core's existing public `getAttributes()`.

**Consequence.** A required-on-update attribute the client legitimately omitted but
that is present (valid) in stored state no longer spuriously `422`s — the merged map
carries it. A per-field rule on an untouched stored field re-validates benignly (the
value was persisted valid). A stored value resolving to `null` is **not** folded in:
a stored null carries no value to evaluate and folding it would flip an absent
optional into a present-null that trips `NotNull`/the comparison, so nulls are
dropped from the stored map before the merge (an incoming explicit null still
overrides, being merged on top). The simplest correct behaviour — validate the full
merged map through every rule — is the one chosen; the only carve-out is dropping
stored nulls.

**Follow-up (HALF B — pivot, ADR 0050).** The pivot half extends the same `validate()`
signature to fold an existing relationship member's **stored pivot row** under its
incoming linkage meta, validating a genuinely-new member in create context and an
existing member in update context (superseding the always-create-context band-aid
documented on `validateRelationshipLinkage()`/`pivotMetaErrors()`), over a new
read-side `DataProvider` seam (`fetchRelationshipPivot()`). The attribute signature
settled here (`$existingObject` as the trailing optional parameter) is the seam that
half built on.
