# A relation is routed by its URI field name and falls back to its name

Core 1.7 keys a relation's related and relationship paths by its URI field name (core ADR 0161), so the bundle now resolves the routed `{relationship}` segment, and an Atomic Operations `href`, with `AbstractResource::relationAtUriFieldName()` and rewrites the route attribute to the relation's name right after routing, so the `Target` and everything that reads the route see the name. When no relation is served under the segment, it is tried as a relation name before the request `404`s, because a renamed relation used to be served only under its name and existing clients still call that path. The fallback is deliberately undocumented: the links and the OpenAPI document name only the URI field name.

## Considered options

- Serve the URI field name only, as core's ADR assumes. It is cleaner, but it turns every existing client of a renamed relation into a `404` on upgrade.
- Translate the segment inside `TargetResolver` or the request listener. Both run too late or too narrowly for `JsonApiRoute::of()` and other listeners, which would then disagree with the lifecycle about which relation the request names.
