# Declarative resource authorization via security expressions on the lifecycle hooks

Authorizing a JSON:API operation today means writing a lifecycle subscriber or a
resource hook method by hand. We add an **API-Platform-style declarative layer**: a
resource declares Symfony Security ExpressionLanguage strings on
`#[AsJsonApiResource(security:, securityCreate:, securityUpdate:, securityDelete:,
securityRead:)]`, and a built-in `ResourceSecuritySubscriber` evaluates the matching
expression at the per-operation lifecycle hooks (bundle ADR 0042) — at the **before**
hooks for writes (create/update/delete and relationship mutation, so a denial aborts
*before* any persist or side-effect) and at `AfterFetchOne` for a single read — by
calling `AuthorizationCheckerInterface::isGranted(new Expression($expr), $object)` and
throwing `AccessDeniedException` when it is false. The subject `$object` is the
operation's entity (the hydrated entity on create, the loaded+changed entity on
update, the parent on relationship mutation, the loaded entity on delete/read), so
`is_granted('EDIT', object)` reaches an ordinary Voter. Per-operation overrides fall
back to the `security` default; a null expression leaves that operation ungated.

The whole layer stays **out of core** — core only supplies the hook seam — and is
**bundle-optional**: `symfony/security-core` + `symfony/expression-language` are
`suggest`, so the subscriber, the type-keyed `ResourceSecurityRegistry` (built by a
compiler pass from scalar tag attributes), and the new exception mapping activate only
when those classes exist, and the subscriber is additionally a no-op when no firewall
is configured (`security.authorization_checker` is injected `->nullOnInvalid()`). The
route-scoped `ExceptionListener` gains the ~5-line enabler: `AccessDeniedException` →
`403` (or `401` when the request is unauthenticated, mirroring Symfony's own
access-denied handling) and `AuthenticationException` → `401`, since neither is an
`HttpExceptionInterface`. **Collection reads are deliberately not gated** here:
row-level read authorization belongs in the query-scope (a Doctrine extension hiding
rows → `404`/absent), not a single all-or-nothing gate.

One consequence forced a change of ordering: the bundle drives the entire JSON:API
flow (including firing the lifecycle hooks) from its `kernel.request` listener, which
previously ran at priority 16 — **before** the Security Firewall (priority 8), so the
token storage was still empty when an expression evaluated. The `RequestListener` now
runs at priority 4, after the firewall, so an authenticated token is present when the
hooks fire. This is safe (the router at priority 32 has still populated the route
defaults the listener reads) and is the natural ordering for any
authorization-at-dispatch design.
