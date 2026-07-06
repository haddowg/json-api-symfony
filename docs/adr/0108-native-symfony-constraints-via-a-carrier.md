# Native Symfony constraints attach via a `NativeConstraints` carrier

- **Status:** accepted

A field or filter can wrap one or more raw Symfony `Assert\*` `Constraint` objects in
a bundle `NativeConstraints` value object and attach them with core's `constrain()`. The
`ConstraintTranslator` recognises the carrier and passes the wrapped constraints straight
to Symfony's validator (running in the same `422`-with-`source.pointer` pass, and — since
the filter-value validator shares the translator — on `filter[…]` values too).

**Why.** The existing extension point, a class-keyed `ConstraintTranslatorInterface`,
needs a bespoke `ConstraintInterface` value object **and** a registered translator per
rule — the right shape for a reusable, portable constraint, but heavy for a one-off
Symfony-native check (`NotCompromisedPassword`, an app's own `Constraint`). `NativeConstraints`
is the zero-registration escape hatch: the rule is already a Symfony constraint, so there
is nothing to translate. It is the canonical first-party instance of the `constrain()`
seam.

**Schema is opt-in, and neutral.** `NativeConstraints` implements core's `ProvidesJsonSchema`
(core self-describing-constraint seam), so it is invisible to the generated OpenAPI /
JSON Schema by default and documents only when the author declares the value schema the
rule implies via `->schema(fn (Schema $s) => …)` — a closure over core's framework-neutral
`Schema` VO, so a byte-compatible twin (the Laravel `LaravelRules` carrier) emits the
identical fragment.

## Consequences

A `NativeConstraints` couples the field to Symfony (it is not portable to another
framework integration), so the guidance is: prefer a core constraint when one exists
(portable + documented), and reach here only for a genuinely Symfony-native rule. The
translator gains one arm before its `translateExtension` fallback; no new service or tag.
