# The validator bridge cascades `Obj` and `OneOf` children

- **Status:** accepted

The Symfony Validator bridge (`ResourceValidator`) validates the children of the two
new composite attribute types (core ADRs 0118/0119), surfacing per-child `422`s with
`/data/attributes/<field>/<child>` pointers:

- **`Obj`** validates identically to `Map` — a nested `Collection` mirroring the
  top-level one carries the per-child rules (one level deep, same create/update
  presence resolution, ADR 0020). `Obj` simply joins `Map` in the `nestedCollection`
  cascade.
- **`OneOf`** validates **value-dependently**, as a document-level pass (like the
  cross-field `CompareField`s): the incoming discriminator selects the variant, whose
  children are then validated through a nested `Collection`. A static per-field
  `Collection` cannot express this — which variant's rules apply depends on the value.
  An array whose discriminator names no variant is one `422` at
  `/data/attributes/<field>/<discriminator>`; a non-array value one at
  `/data/attributes/<field>`.

**Why.** Core declares the composite children + their constraints but never executes
them; the host bridge owns validation. `Obj` is structurally `Map` (children in one
value), so it reuses the existing cascade verbatim. `OneOf`'s variant selection is
intrinsically value-dependent, so it cannot ride the static `Collection` and runs in
the same document-level phase that already handles value-dependent rules — keeping the
pointer semantics identical to every other attribute.

## Consequences

The cascade stays one level deep (ADR 0020): a child that is itself an `Obj`/`OneOf`
is not descended into here. `OneOf` presence/nullability is still resolved by the main
`Collection` pass (a required union must be present); only its variant children move to
the value-dependent pass. Witnessed end-to-end over HTTP by `CompositeValidationTest`
(valid create, `Obj` child pointer, `OneOf` variant-child pointer, unknown
discriminator). The `Shape` constraint's combinator validation, the Doctrine
JSON-column mapping, and the example-app surface are separate follow-ups.
