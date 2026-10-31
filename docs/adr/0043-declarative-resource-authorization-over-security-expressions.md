# Authorization is declared as security expressions and enforced on the lifecycle events

A resource declares Symfony ExpressionLanguage strings (`security`, and per operation `securityCreate`, `securityRead`, `securityList`, `securityUpdate`, `securityDelete`), which a subscriber evaluates at the before events with the operation's entity as subject (none for the collection read), so an ordinary voter applies and a denial aborts before anything is written. The layer stays out of core, which supplies only the hook seam. `true` or `false` in place of an expression documents the operation as secured or public in OpenAPI without enforcing anything, for operations an external firewall guards or that are public under a secured document default.

## Consequences

- `security` cascades to every operation including the collection read, which has no subject, so a per-object default needs an explicit `securityList`.
- A declared expression nothing can evaluate (no `symfony/security-core`, `symfony/expression-language` or firewall) fails the build instead of running ungated.
- Which rows a list holds is a query scope (ADR 0008), not a gate.
