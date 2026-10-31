# Relationship endpoints are parametric routes, resolved to a relation after routing

The related and relationship routes take `{relationship}` as a parameter (one route per shape, not one per relation), so the handler rather than the router decides whether a relation exists and is exposed: an unknown, hidden or unexposed relation is a semantic JSON:API `404` (`RelationshipNotExists`), and a prohibited verb is the relation's own `403`. The segment is matched against the relations' URI field names and rewritten to the relation's name straight after routing, so the `Target`, `JsonApiRoute::of()` and every other listener agree on which relation the request names.

## Considered Options

- Serve the URI field name only. It is cleaner, but every existing client of a renamed relation would `404` on upgrade, so an unmatched segment is tried as a relation name before failing. That fallback is deliberately undocumented: links and the OpenAPI document name only the URI field name.
- Translate the segment inside `TargetResolver` or the request listener. Both run too late or too narrowly for other listeners, which would then disagree with the lifecycle.
