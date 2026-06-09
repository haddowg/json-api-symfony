# Validation is a Symfony Validator bridge over core's constraint vocabulary

Core declares a resource's field constraints as metadata value objects but
**never executes them** — execution is the adapter's job. The bundle gives them
teeth by translating each `ConstraintInterface` into the equivalent Symfony
`Constraint` (`MinLength`→`Length`, `In`→`Choice`, `Min`/`Max`→`GreaterThanOrEqual`/`LessThanOrEqual`,
`Each`→`All`, …) and running them through Symfony's validator. Validation is
**document-first**: the resource's `attributes` are validated as the request sends
them, before hydration, so a violation maps cleanly to a `/data/attributes/<name>`
JSON Pointer. Presence and nullability are not translated as value rules — they are
resolved against the create/update `Context` into a Symfony `Collection`'s
`Required`/`Optional` wrapper plus `NotBlank`/`NotNull` (a required field must be
present and non-empty on create, but a partial update may omit it). Violations
become a `ValidationFailed` (`422`) carrying one pointer-bearing `Error` each — the
typed exception core does not ship — rendered by the existing exception listener.

The bridge is **optional**: `symfony/validator` is a `suggest` dependency, so it is
wired only when installed, and the handler's validator is nullable — without it,
writes run unvalidated. Core's one shipped `Custom` constraint (`email.strict`) is
handled through an `$id`-keyed `CustomConstraintTranslatorInterface` extension
point applications extend by tagging a service.

The closure-carrying constraints initially deferred here are now translated. They
have no stock Symfony equivalent that accepts a PHP closure (Symfony's own `When`
takes an ExpressionLanguage string, and the comparison constraints coerce only the
*bound*, never a raw string value), so each becomes a `Callback`: `When` evaluates
its condition and, when true, re-validates the value against the translated inner
constraints; `After`/`Before`/`Between` coerce the value to a `\DateTimeImmutable`
and compare it against a bound resolved at validation time — so a closure bound such
as "now" reflects the moment of the request (exercised under a frozen `symfony/clock`
in the conformance suite). `Timezone` was removed from core rather than translated:
an ISO-8601 value on the wire carries an offset, not a named zone, so it could not be
resolved well (core ADR 0020). With those handled, the bridge's `default` arm is
reached only by a constraint outside core's vocabulary.

The audit also surfaced genuine vocabulary gaps core has no constraint for —
cross-field rules (`endDate after startDate`), a `Valid`-style cascade into
nested/related resources, and DB-uniqueness (`UniqueEntity`) — recorded for the v1
core-surface review rather than worked around here.
