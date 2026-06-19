# Serve the OpenAPI document from a cache-warmer artifact, configured under `json_api.openapi.*`

The bundle implements core's OpenAPI metadata contract (the `MetadataSource`,
Slice-4 stage A) and projects it via a per-server `DocumentFactory`; document
**generation never happens per request** (it walks the whole registry). Instead a
`DocumentWarmer` (an `isOptional() === true` `CacheWarmerInterface`) pre-builds each
server's document + per-type JSON Schemas at `cache:warmup` into a stable
`%kernel.cache_dir%/json_api_openapi/` sub-path (the shared `ArtifactStore`), and the
`OpenApiController` serves that artifact `O(file read)` — lazy-building via the factory
only when the artifact is absent (dev, where resources change between edits; the build
is cached back only in `kernel.debug` so a read-only prod filesystem is never written).
The warmer is optional so a docs build failure never breaks a deploy, and may also
emit a fully static `.json`/`.yaml` to a configured `public_path` for a CDN.

The document routes (`GET /docs.json`, `GET /{server}/docs.json`) are emitted by a
dedicated `OpenApiRouteLoader` (route type `jsonapi_openapi`, imported once like the
CRUD `jsonapi` routes) **only when the expose gate passes** — `kernel.debug` is true OR
`json_api.openapi.expose_in_prod` is true (D9) — so the document is unreachable over
HTTP outside debug unless opted in; the CLI export stays available regardless. The
document is served as `application/json` (it is OpenAPI, not a JSON:API document) and
its routes carry no JSON:API route marker. Multi-server runs one document per server by
default; `multi_server: combined` emits the single json-path route only (D5).

The whole subsystem is configured under a new `json_api.openapi.*` tree (info /
servers / security schemes + default requirement / tag definitions / external docs /
`enum_value_descriptions` / `public_path`, design §6). Because the compiled container
cannot dump value objects as service arguments, the OAS info/security/tag/server VOs
are rebuilt **at runtime** by a `ServerDocumentConfigProvider` from a pure-scalar
`haddowg_json_api.openapi` parameter and injected into the `MetadataSource` via a
factory service — keeping the OAS VOs out of the dumped container. `symfony/console`
became a direct dependency (the CLI is always available, D6); `symfony/yaml` stays a
suggested soft dependency (the YAML export and the static `.yaml` are gated on its
presence). The Slice-5 `OpenApiFactoryInterface` decorator is not wired yet — the
`DocumentFactory` is the clean seam it will decorate.
