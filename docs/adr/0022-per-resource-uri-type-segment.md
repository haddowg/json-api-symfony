# A resource's URL segment is its uriType, distinct from its JSON:API type

A resource may declare a URL path segment (its `static $uriType`, core ADR 0031)
that differs from its JSON:API `type` — a plural (`/books` for type `book`) or a
kebab-cased name. The route loader reads `$uriType` **statically** from the
resource class-string (exactly as it already reads `$type`, with no instantiation,
honouring its no-instantiation stance) and emits the route *paths* at the segment;
the create `Location` header uses the segment via the resource's `uriType()`
(resolved through the {@see TypeMetadataResolver}, falling back to the type for a
bare pair). Core's by-convention relationship links already use the segment, so a
resource's emitted links and the routes that serve them agree.

Route **names** and the `_jsonapi_type` route default keep the JSON:API type, so
the `TargetResolver`, operation dispatch, provider/persister and serializer
resolution, and the rendered document `type` member are all unchanged — only the
URL *path* differs. Reusing the existing static-property channel keeps `uriType` a
single source of truth (the resource), so the route paths and the link paths can
never diverge; we deliberately did **not** add a `uriType` field to
`#[AsJsonApiResource]`, which would be a second, desyncable source.

uriType is purely a routing/rendering concern — identical on every data provider —
so the witness (a `book` resource at the `books` segment) runs on the in-memory
kernel only, rather than duplicating a provider-orthogonal proof on Doctrine.
