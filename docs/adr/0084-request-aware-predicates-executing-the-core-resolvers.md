# Request-aware predicates: executing the core resolvers

Core widened the field-visibility / writability / relationship-authz axis (and the
validation `when()` condition) to be **request-aware** (core ADRs 0079/0080): a
field may be `hidden(fn)`/`writeOnly(fn)`/`readOnly(fn)`, a relation
`cannotReplace/cannotAdd/cannotRemove/cannotBeIncluded(fn)`, and a `when()` condition
`fn($value, $request)`. The bundle is the **execution site** — it consumes the new
`isHiddenFor`/`isReadOnlyFor`/`isWriteOnlyFor`/`isIncludableFor`/`allowsReplaceFor`/
`allowsRemoveFor`/`allowsAddFor` resolvers at every request-aware path so the
predicates actually take effect end-to-end on both providers.

The threading is the change:

- **Validation `when()`.** `ConstraintTranslator::translate()/translateAll()/
  alternatives()/conditional()` take an optional `?JsonApiRequestInterface` and invoke
  the condition as `$condition($value, $request)`. `ResourceValidator` threads the
  inbound request (already a `validate()` param) into the three `translate()` calls.
  The condition is widened, not repurposed — a presence rule (`Required`/`Nullable`)
  wrapped in a `when()` is resolved by `ResourceValidator`'s presence resolution
  (`hasRequired`/`hasNullable` now descend into a `When` whose condition holds for the
  caller and incoming value), so a *conditionally-required* field 422s for the matching
  caller; the `When`-callback skips those presence markers (they carry no value-constraint
  translation and would fail loud).

- **Read-only / validation consistency.** The read-only validation skip switches to
  `isReadOnlyFor($creating, $request)` so validation and hydration agree — otherwise a
  conditionally-read-only field would be validated (e.g. required) but never hydrated for
  the same caller, surfacing a spurious 422. The Map-child read-only skip stays static
  (Map-child visibility is an explicit non-goal, bundle ADR 0020).

- **The live relationship-mutation gate.** `CrudOperationHandler::guardMutability()` —
  the relationship-endpoint authz gate — takes the in-scope `$body` (request) and
  `$parent` (object) and switches every `allows*()` to `allows*For($body, $parent)`.
  Without this the relationship-authz predicates would be silently ignored on the
  `PATCH/POST/DELETE …/relationships/{rel}` endpoints. `extractRelationships()` switches
  to `isReadOnlyFor($creating, $body)`, mirroring core's request-aware
  `hydrateRelationships()`, so a `readOnly(fn)` relation embedded in a whole-resource
  write is gated per caller.

- **The replacement gate on embedded whole-resource writes.** The same
  `guardMutability()` is also enforced on the embedded write path: a relationship
  embedded in a `data.relationships` member is applied as a full replacement
  (`Mode::Replace`), so on a whole-resource **`PATCH`** `applyRelationships()` runs the
  gate per embedded relation before the persister applies — a `cannotReplace(fn)` relation
  embedded in an UPDATE is `FullReplacementProhibited` (403) exactly as at the dedicated
  endpoint, closing the bypass where it was silently replaced. The gate is **skipped on a
  `POST`** (create-vs-update is the `creating` flag the write path already threads from
  the operation): a create sets the initial state (nothing to replace) and gating it would
  make a `cannotReplace` relation impossible to ever set, since it has no relationship
  endpoint either. `cannotAdd`/`cannotRemove` have no embedded analogue — an embedded write
  is always a full set, never an incremental add/remove.

- **Includability.** `RelatedIncludeBatcher` switches `isIncludable()` →
  `isIncludableFor($request, $entities[0])`, so a relation non-includable for *this*
  caller isn't eagerly batched (matching the 400 the transformer raises if the caller
  names it).

- **Forwarders.** The `getNonIncludableRelationships` implementors `PivotMetaSerializer`
  and `PivotParentSerializer` gain the `$request` param (logic unchanged) to match the
  widened core `IncludeControlsInterface`.

**Boundaries (the MVP edges):** filter-side `when()` and the entity-level pass
(`validateEntity`) pass `null` for the request — they have no inbound write document to
branch on and stay static for now; pivot-meta validation passes `null` too (pivot-field
visibility is a non-goal); and Map-child *visibility* stays static (only a Map child's
`when()` condition sees the request). OpenAPI generation is request-independent and
documents the **superset** — a sometimes-hidden field still appears in the schema, a
sometimes-prohibited verb is still exposed — so the static getters stay permissive for a
closure-declared member and the schema/build-time/relation-lookup paths are unaffected.

Dual-provider conformance (`RequestAwarePredicateConformanceTestCase` + the in-memory /
Doctrine subclasses, the `badges` fixture) varies the caller purely by the inbound
`X-Role` header (`JsonApiRequestInterface` is a PSR-7 request, so no security plumbing)
and asserts every predicate on both providers: a hidden attribute present for admin /
absent otherwise (and absent for a guest in every serialization context — single,
collection, included, related — via a read-only inverse `medals → badges` relation), a
write-only secret accepted but never rendered, a read-only-on-update attribute ignored
for a guest / writable for an admin, a gated relationship mutation 403 for a guest / 2xx
for an admin (at the dedicated endpoint **and** embedded in a whole-resource `PATCH`,
while the gated relation embedded in a `POST` is allowed — the create exception), a gated
`?include` 400 for a guest / expanding for an admin, and a conditionally-required
attribute 422 for an admin omitting it / accepted for a guest.
