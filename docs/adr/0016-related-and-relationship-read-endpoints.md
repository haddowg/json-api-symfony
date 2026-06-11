# Related and relationship read endpoints are parametric routes with semantic 404s

The route loader auto-registers two read endpoints per resource alongside the
resource routes: `GET /{type}/{id}/{relationship}` (related resources) and
`GET /{type}/{id}/relationships/{relationship}` (relationship linkage). They carry
a `_jsonapi_relationship_endpoint` route default (`false`/`true`) which the
`TargetResolver` reads — together with the `{relationship}` path attribute — to
build the relationship-aware `Operation\Target`. The four-segment linkage path and
the three-segment related path differ in segment count (and from the two-segment
resource route), so they never shadow one another; the linkage route is emitted
first so the literal `relationships` segment is never captured as a relationship
name.

The `CrudOperationHandler` gains a `FetchRelatedOperation` arm and a
`FetchRelationshipOperation` arm. Both load the parent through the read provider
(a JSON:API `404` when it is missing) and resolve the relation by name via core's
`AbstractResource::relationNamed()`. An **unknown relationship is a semantic
JSON:API `404`** (`RelationshipNotExists`) rather than a router 404, because the
parent route matched and the path is well-formed — only the named relationship is
absent. The related arm reads the related domain value(s) off the parent without
serializing (`RelationInterface::readValue()`) and renders them through the
*related* type's serializer as a `RelatedResponse` (single, `data:null` for an
empty to-one; or collection per cardinality); the relationship arm renders an
`IdentifierResponse` through the *parent* serializer. `?include` flows unchanged
through the existing transformer-driven render path on both the resource and the
related endpoints. Advanced query parameters on a related collection (filter / sort
/ page on the related type) are out of scope for this slice. Polymorphic `MorphTo`
related-resource endpoints (per-item serializer resolution) are deferred.
