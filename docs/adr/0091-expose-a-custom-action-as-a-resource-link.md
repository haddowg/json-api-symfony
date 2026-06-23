# Expose a custom action as a security-aware resource link (`asLink`)

A custom `#[AsJsonApiAction]` can set `asLink: true` to be published as a `links` member
(keyed by the action's `path`) on every rendered resource of its mount type, so a client
discovers the action straight from the resource it acts on. We render it through core's
out-of-band `ResourceLinkContributorInterface` seam — a single `ActionLinkContributor`
threaded onto each per-server `Server` via `Server::withResourceLinkContributor()`,
mirroring the existing `RequestScopedRelationship*` wiring — rather than burdening every
resource's own `getLinks()` with router and authorization knowledge it does not have (and
could silently drop). The contributor resolves the request's server from `_jsonapi_server`
so each server contributes only its own `asLink` actions and generates each URL from that
server's namespaced route name.

The link is **security-aware**: when the action declared a `security` expression, the link
renders only when the requester would pass that gate, evaluated through the SAME
`AuthorizationCheckerInterface::isGranted(new Expression(...), $object)` the per-action
`BeforeActionEvent` gate (`ResourceSecuritySubscriber::onBeforeAction`) uses — so a client
never sees a link to an action it cannot invoke, and the visibility decision cannot drift
from the invocation decision. With no firewall wired a gated action's link is suppressed
(fail-closed). `asLink` is **resource scope only** — a collection action has no resource to
hang a link on — so `asLink: true` on a `Collection`-scope action is a build-time
`\LogicException` (asserted in both the attribute constructor and the compiler pass).
