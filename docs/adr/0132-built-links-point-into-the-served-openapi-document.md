# Built links point into the served OpenAPI document

Core renders the links it builds as link objects and gives each a `describedby` naming the operation that describes its target, from a resolver the adapter supplies (core ADR 0158). The bundle sets that resolver on each request's Server in `ServerProvider::forRequest()`, pairing the server's document URL with the operations its document carries. The URL defaults to the host-relative path of the bundle's own document route, generated against the request so it includes the import prefix and front-controller base path, and `json_api.openapi.document_url` replaces it for a document published elsewhere. The operations come from a small path-to-methods map the warmer writes beside each document, so a request never builds or loads the document to describe a link.

## Considered options

- Set the resolver once on the memoized Server. The default URL is a path generated against the request, and a long-running worker would freeze the first request's base path into every later one.
- Keep `DescribedbyListener` stamping the whole-document URL. Core lets a `describedby` set through `withDescribedby()` win over its per-operation one, so the listener would have hidden every operation pointer. It now runs only for a non-error response whose operation the document doesn't carry (an Atomic Operations result), which still points at the whole document.
- Resolve against the per-request document after serve decorators run (ADR 0131). That would mean decorating the document on every API request. A `describedby` may therefore name an operation a serve decorator removed for that request; the build-time document still has it.

## Consequences

`link_objects` is a per-server setting like `output_timezone`, because it's a property of the Server that renders the links, while `describedby` and `document_url` sit with the rest of `json_api.openapi`, which has no per-server overrides; `{server}` in `document_url` covers per-server documents. In combined mode every server points into the one document, and the document describes the default server's link form. When the map is missing (no warmup) the provider builds the document once per process and tries to write the map, swallowing a write failure on a read-only cache dir.
