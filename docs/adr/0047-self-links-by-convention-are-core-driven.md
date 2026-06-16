# Self links by convention come from core; the bundle witnesses them

Core now emits the two spec-recommended (SHOULD) `self` links by convention
(core ADR 0054): a **resource-level** `data.links.self = {baseUri}/{uriType}/{id}`
on every resource object (primary data *and* `?include`'d resources), and a
**top-level document** `links.self` (the request URI) on every data/resource
document — single, collection, related, relationship and meta — but not error
documents. Both links derive from ingredients the bundle already supplies (the
configured `base_uri` threaded to the `Server`, the serializer's `uriType()`/type,
the resource id, and the PSR-7 request the kernel listeners already resolve), so
**no bundle source change was needed** — the feature lands purely as core behaviour
the bundle consumes through the symlinked path repo.

The bundle's obligation was to **witness the new convention faithfully** rather than
let the existing loose assertions stay silent about it. The dual-provider
conformance suites now assert the actual self URLs (never weakened to absence
checks): the resource self on a single resource, a collection item, an included
resource and a created resource; and the top-level self on single, collection,
related and relationship documents — including its percent-encoded query string and
the rule that a **paginated** document's per-page self (carrying the resolved page
params) wins as the top-level self while `first`/`prev`/`next`/`last` are preserved.
Both providers produce identical self URLs (the links are storage-agnostic), so the
same assertions run on the in-memory and Doctrine kernels.

The per-resource **opt-out** is `AbstractResource::emitsSelfLink(): bool` (default
`true`; the capability interface is `haddowg\JsonApi\Serializer\SelfLinkAwareInterface`,
a serializer not implementing it still emits). The witness is `GizmoResource`,
which overrides it to `false`: that resource then carries no `data.links.self`
while the top-level document `self` is unaffected — the opt-out is resource-scoped.
The resource self is also skipped when the id is empty (a not-yet-persisted echo)
or when `getLinks()` already supplied a hand-written self (which wins).
