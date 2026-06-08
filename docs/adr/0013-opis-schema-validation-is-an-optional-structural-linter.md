# opis JSON-Schema validation is an optional structural linter, separate from the Validator bridge

The bundle has two validation layers with different jobs, and keeps them
separate. The **Symfony Validator bridge** (ADR 0012) is always-on *semantic*
validation of a resource's declared constraints, rendered as `422`. This adds an
optional *structural* check: core's `DocumentValidator` validating a write body
against the JSON:API JSON Schema (via `opis/json-schema`), rendered as core's
`400 RequestBodyInvalidJsonApi` — it catches documents that are malformed *as
JSON:API* (a disallowed resource-object member, a wrong member shape) which the
semantic layer and the hydrator would not.

It is off by default and gated behind `json_api.schema_validation`, because
`opis/json-schema` is a `suggest` dependency and full-document schema validation
is a dev/CI affordance, not something to pay for on every production write.
Enabling it without the package installed fails the container build with a clear
message rather than at the first request. The check runs in the existing
`RequestListener` (an injected, nullable `DocumentValidator`) right after core's
lightweight top-level-member checks and before dispatch — reusing the lifecycle
front rather than adding a parallel listener that would re-derive the parsed
request.
