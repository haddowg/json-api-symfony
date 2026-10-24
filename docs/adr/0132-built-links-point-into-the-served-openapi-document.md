# Built links point into the served OpenAPI document

Core renders the links it builds as link objects and gives each a `describedby` naming the operation that describes its target, from a resolver the adapter supplies (core ADR 0158). The bundle sets that resolver on each request's Server in `ServerProvider::forRequest()`, pairing the server's document URL with the operations its document carries. The URL defaults to the host-relative path of the bundle's own document route, generated against the request so it includes the import prefix and front-controller base path, and `json_api.openapi.document_url` replaces it for a document published elsewhere. The operations come from `SpecDescribedByResolver::operationsOf()`, which the warmer writes beside each document as a small path-to-methods map, so a request never builds or loads the document to describe a link.

## Considered options

- Set the resolver once on the memoized Server. The default URL is a path generated against the request, and a long-running worker would freeze the first request's base path into every later one.
- Keep `DescribedbyListener` stamping the whole-document URL, or keep it as a fallback for responses core can't describe. Core lets a `describedby` set through `withDescribedby()` win over its per-operation one, so stamping would hide every operation pointer, and with the atomic endpoint's path passed to `AtomicOperationsOperation` an Atomic Operations result now gets its own `POST` pointer. The listener is gone.
- Resolve against the per-request document after serve decorators run (ADR 0131). That would mean decorating the document on every API request. A `describedby` may therefore name an operation a serve decorator removed for that request; the build-time document still has it.

## Consequences

`link_objects` is a per-server setting like `output_timezone`, because it's a property of the Server that renders the links, while `describedby` and `document_url` sit with the rest of `json_api.openapi`, which has no per-server overrides; `{server}` in `document_url` covers per-server documents. In combined mode every server points into the one document, and the document describes the default server's link form. When the map is missing (no warmup) the provider builds the document once per process and tries to write the map, swallowing a write failure on a read-only cache dir.
