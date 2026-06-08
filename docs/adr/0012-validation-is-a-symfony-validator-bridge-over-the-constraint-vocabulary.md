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

Deliberately **deferred** to a follow-up (loud `LogicException`, never a silent
skip): the closure-based `When` and the date/timezone value constraints
(`After`/`Before`/`Between`/`Timezone`), which need a conditional-evaluation
validator and a date-bearing fixture to exercise honestly. The audit also surfaced
genuine vocabulary gaps core has no constraint for — cross-field rules
(`endDate after startDate`), a `Valid`-style cascade into nested/related resources,
and DB-uniqueness (`UniqueEntity`) — recorded for the v1 core-surface review rather
than worked around here.
