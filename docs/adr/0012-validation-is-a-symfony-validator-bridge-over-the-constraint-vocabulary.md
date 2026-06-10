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
writes run unvalidated. Email strictness is read off the typed `EmailFormat::$strict`
flag (strict mode needs `egulias/email-validator`, degrading to HTML5 without it).
A constraint outside core's built-in vocabulary is delegated to a class-keyed
`ConstraintTranslatorInterface` extension point — applications register a translator
for their own typed constraint VO by tagging a service; the bridge consults them
(first `supports()` match) and fails loud if none matches. This replaces the removed
`$id`-keyed `Custom` escape hatch (core ADR 0021).

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
resolved well (core ADR 0020). With those handled, the only constraints reaching the
`default` arm are those outside core's built-in vocabulary — which it routes to the
extension translators above.

`CompareField` (a cross-field rule, e.g. `endDate after startDate`) is the one
constraint the bridge does **not** validate per-field: the per-field `Collection`
sees each value in isolation, so the bridge evaluates `CompareField` at the
**document** level after the `Collection` pass — reading the sibling value straight
from the `attributes` array, coercing the pair to numbers or dates where it can,
and pointing any violation at the owning field. This closed the cross-field gap the
audit had recorded.

The remaining recorded gaps are a `Valid`-style cascade into nested/related
resources and DB-uniqueness (`UniqueEntity`).
