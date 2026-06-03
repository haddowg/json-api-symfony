# Routing maps Symfony routes to operations via a Target resolver, with convention auto-routes on top

Core ships no router, so something must attach an `Operation\Target`. The bundle
ships one primitive — a Target resolver that reads the `Target` from route defaults
(`_jsonapi_type`, `_jsonapi_server`) — and, on top of it, a route loader that
auto-registers the standard JSON:API endpoint set per registered resource
(overridable and opt-out-able). Teams wanting explicit routes declare their own and
use the resolver directly.

The result is "register a resource → working endpoints" while staying router-native:
real named routes, per-route firewall/security, no catch-all path parsing. The
convention-versus-explicit trade-off is resolved by making the resolver the stable
contract and the auto-routes removable sugar; multi-version server selection is just
the `_jsonapi_server` route default.
