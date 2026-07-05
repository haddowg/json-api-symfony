# The validator bridge value-validates a `Shape` through the core `SchemaValueValidator`

- **Status:** accepted

The Symfony Validator bridge (`ResourceValidator`) validates an attribute carrying a
`Shape` composite-schema constraint (core ADR 0120 — `oneOf`/`anyOf`/`allOf` of raw
member schemas) by delegating to the core `SchemaValueValidator` (core ADR 0121), which
runs the constraint's compiled `Schema` against the value with opis and returns one
`422` `Error` per leaf violation, each pointer being `/data/attributes/<field>` plus the
opis instance pointer. Like `OneOf` and the cross-field `CompareField`s, it runs as a
**document-level pass** (a whole field value against its composite schema), not through
the static per-field `Collection`, and is skipped there (`ResourceValidator::valueConstraints`
`continue`s on a `Shape`).

**Why.** A `Shape` carries *raw JSON Schema* no Symfony rule can translate — opis is its
only validator, and that execution is framework-agnostic, so it lives in core rather than
being re-derived in this bundle and the Laravel package (core ADR 0121). The bridge's job
shrinks to: resolve each `Shape`-constrained field, compile its `Schema`, hand value +
schema to the one core validator, and fold the returned `Error`s into the same `422`
response every other attribute violation produces.

## Consequences

opis/json-schema is a `suggest` dependency, so the core `SchemaValueValidator` is
registered and injected into `ResourceValidator` only when it is installed, and
`->nullOnInvalid()` otherwise — matching the optional-linter posture the structural
`json_api.schema_validation` toggle already has, but **independent of that toggle** (the
toggle gates the structural document linter; a `Shape`'s value validation is on whenever
opis is present). When opis is absent a `Shape` still projects its OpenAPI shape but is
not value-validated. Witnessed end-to-end over HTTP by `CompositeValidationTest` (a valid
discriminated-`oneOf` create, a variant missing a required member, an unknown
discriminator — all pointed under `/data/attributes/contact`).
