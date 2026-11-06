# The document marks only what a type declares deprecated, and every marked operation sends the header

The OpenAPI deprecation markers read the same `#[AsJsonApiResource(deprecation:, sunset:)]` declaration that drives the `Deprecation` and `Sunset` headers, so the document and the responses can't disagree about a type. The `json_api.defaults` and per-server defaults still send their headers but mark nothing in the document, because a fleet-wide default is a migration notice to every client rather than a statement about one type, and stamping the deprecation contract on every document over it would be noise. Going the other way, an operation named in `deprecatedOperations` or an action declared `deprecated` sends a bare `Deprecation: true` at runtime too, unless the type or a default supplies a date, so a client that only reads headers learns as much as one that reads the document.

## Consequences

`sunset` stays a free string sent verbatim. The document reads a date out of it, accepting the three HTTP-date forms, a structured-field `@<seconds>` date and ISO 8601, and leaves out the sunset sentence for anything else instead of failing the build, because an unparseable value was valid before the document read it.
