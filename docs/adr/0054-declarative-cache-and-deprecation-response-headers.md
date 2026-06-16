# Declarative HTTP cache + deprecation/sunset response headers

Setting an HTTP cache directive or a deprecation signal on a JSON:API endpoint
today means writing an after-hook that mutates the response by hand. We add a
**declarative bundle-only layer** for two cross-cutting HTTP-response concerns
(API-Platform gaps G7 + G16): a resource declares cache directives and/or a
deprecation/sunset on `#[AsJsonApiResource(cacheHeaders:, deprecation:, sunset:,
sunsetLink:)]`, a global `json_api.defaults.cache_headers` / `…deprecation` /
`…sunset` supplies fleet-wide defaults, and **one** route-scoped `kernel.response`
listener (`ResponseHeadersListener`) emits the resulting headers. Both stay out of
core — they are pure HTTP-response semantics (RFC-7234 caching, the IETF
`Deprecation` header field, and the RFC-8594 `Sunset` header) carried on the
HttpFoundation `Response`, with no JSON:API-document interaction.

**G7 — cache headers.** `cacheHeaders` is a scalar map (`max_age`, `s_maxage`,
`public`/`private`, `no_cache`, `must_revalidate`, `vary`) with an optional nested
`operations` per-read-shape override (`collection`/`read`/`related`/
`relationship`). The `CacheHeaders` value object maps it onto `Cache-Control` via
`Response::setCache()` plus `Vary`. Resolution layers the per-operation override
over the resource-level value over the global default, so a type tunes only what it
declares. Caching is applied **only to a safe (`GET`) successful read** — a write
or an error document never gets a `Cache-Control` (caching either is wrong), gated
on the request method and the final 2xx status the listener reads off the built
response. A type that declares nothing (and has no global default) keeps today's
no-`Cache-Control` behaviour.

**G16 — deprecation + sunset.** `DeprecationHeaders` emits `Deprecation` (the IETF
Deprecation header field, `draft-ietf-httpapi-deprecation-header`) — a bare `true`,
or a date the author formats per the draft revision their consumer expects (the
latest draft wants a structured-field date like `@1688169599`) — and `Sunset` (the
RFC 8594 HTTP-date), plus a companion `Link: <uri>; rel="sunset"` (the RFC 8594
`sunset` link relation) when `sunsetLink` is set. The bundle passes both date values
through verbatim. Unlike caching, these ride **every** response for the type — reads
and writes alike, because a deprecated endpoint is deprecated regardless of method.

Two deliberate choices. The listener runs on **`kernel.response`** (not
`kernel.view`) so the final `Response` — built by the view listener, or for an
error by the exception listener — is in hand and its real status distinguishes a
cacheable read from an error without re-deriving it. And it **never clobbers an app
header**: deprecation/sunset are written only when absent, and cache headers only
when the response carries no meaningful `Cache-Control` (detected by comparing the
computed value against the conservative `no-cache, private` default a bare Response
produces, since `has('Cache-Control')` is always true and a single directive check
is unreliable) — so an after-hook that set caching imperatively still wins. The
per-type config flows through the container as a single JSON-encoded
`response_headers` tag attribute (the nested per-operation overrides do not survive
as a flat scalar tag), which the `ResponseHeadersPass` decodes into the type-keyed
`ResponseHeadersRegistry`. The registry is always present — no optional dependency,
because these are plain HTTP headers.
