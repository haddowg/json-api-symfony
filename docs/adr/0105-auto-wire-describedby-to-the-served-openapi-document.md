# Auto-wire `links.describedby` to the served OpenAPI document

Core made the top-level `describedby` link first-class (a link to a description
document, JSON:API 1.1). The bundle serves exactly such a document at the configured
OpenAPI path, but no response pointed at it, so the member never appeared by default.

A `kernel.view` listener (`DescribedbyListener`, higher priority than the
`ViewListener`) now stamps `links.describedby` onto every JSON:API response via core's
`AbstractResponse::withDescribedby()`, pointing at the served document for the request's
server — the default document, or the per-server document in per-server mode — generated
as a request-host-absolute URL by the router. It is on by default and disabled by
`json_api.openapi.describedby: false`. When the document routes are not registered
(generation disabled or the expose gate closed) URL generation fails and no link is
added, so the member appears only when the document is actually reachable.

We route the link through `withDescribedby()` (a render-time merge) rather than
reconstructing each response's `DocumentLinks`, so it composes with a handler's
`self`/pagination/custom links and an author-set `describedby` still wins.
