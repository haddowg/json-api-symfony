# Documentation-only security booleans on `#[AsJsonApiResource]`

Declarative authorization (ADR 0043) accepted only ExpressionLanguage strings, which
are both **enforced** at runtime and **documented** as secured. Two cases had no
declaration: an operation an external **firewall** protects (the bundle evaluates no
expression, so the OpenAPI document under-claimed it), and a **public** operation
under an otherwise-authenticated API (no way to opt out of the document-level default
security).

The five `security*` parameters now also accept a **bool**, a documentation-only
declaration distinct from an enforced expression (riding core's effective-security
projection + `publicOperations()`, core ADR 0098):

- **`true`** — documented secured (OpenAPI `security` + `401`) with no bundle-evaluated
  gate (an external firewall enforces it).
- **`false`** — documented **public** (operation-level `security: []`, no `401`),
  overriding the document-level default regardless of its value.
- a **string** — unchanged: enforced *and* documented secured.
- **null** — inherit (ungated; documented against the document default).

The value flows the existing path widened to `string|bool|null`: the attribute →
`security_*` service-tag attributes → `ResourceSecurityPass` (now keeps a bool) →
`ResourceSecurity`/`ResourceSecurityRegistry`. The `ResourceSecuritySubscriber` only
evaluates a **string** (a bool is never enforced). The `MetadataSource` classifies each
operation's resolved declaration into `securedOperations()` (string or `true`) and the
new `publicOperations()` (`false`); the projector emits the per-operation `security`
and `401` from the *effective* security. Read security maps to the single-resource read
only (`GET /{type}/{id}`) — the collection read has no per-operation hook and always
follows the document default. Witnessed by the example `ArtistResource`
(`securityRead: false` → a public `GET /artists/{id}` under the `bearer` default) and
its `OpenApiDocsTest`.
