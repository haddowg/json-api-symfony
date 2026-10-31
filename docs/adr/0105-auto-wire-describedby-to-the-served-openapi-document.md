# Built links point at the operation that describes them in the served document

Core gives every link it builds a `describedby` naming the operation that describes its target, from a resolver the adapter supplies. The bundle sets that resolver on each request's `Server`, pairing the server's document URL (its document route resolved against the request, found by controller when the application routes the controller itself, or `json_api.openapi.document_url`) with a path-to-methods map the warmer writes beside the document, so a request never builds or loads the document to describe a link.

## Considered Options

- Set the resolver once on the memoised `Server`: a long-running worker would freeze the first request's base path into every later one.
- Stamp the whole-document URL on every response: it would hide every per-operation pointer.
- Resolve against the per-request decorated document: that decorates the document on every API request, so a `describedby` may instead name an operation a serve decorator removed.
