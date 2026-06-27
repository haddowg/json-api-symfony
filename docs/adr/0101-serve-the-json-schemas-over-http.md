# Serve the JSON Schemas over HTTP alongside the OpenAPI document

The standalone per-type JSON Schema 2020-12 documents were reachable only through the
`json-api:json-schema:export` CLI command. A client codegen wants them over HTTP — a
single fetch to drive an opt-in request/response validation seam — exactly as it
fetches `/docs.json`. The bundle now serves them, mirroring the OpenAPI document
machinery end to end:

- A new `JsonSchemaController` serves `GET {json_schema.path}` (default `/schemas.json`)
  for the default server and `GET /{server}/schemas.json` per named server — the
  per-type schemas gathered into **one object keyed by JSON:API type** (the same
  payload `json-api:json-schema:export` writes to stdout for all types), as
  `application/json`. It serves the pre-built artifact `O(file read)` and lazy-builds
  in dev, exactly like `OpenApiController`.
- The `DocumentWarmer` already wrote the per-type schema artifacts; it now also writes
  the **aggregate** artifact (per server, and the combined one in combined mode) the
  controller reads, plus a static `<server>.schemas.json` when `public_path` is set.
  `JsonSchemaFactory` gains `combined()` (the schema twin of
  `DocumentFactory::combined()`) for combined mode.
- The routes ride the existing `jsonapi_openapi` loader and the **same expose gate** as
  the document (`enabled` AND (`kernel.debug` OR `expose_in_prod`)), with their own
  `json_api.openapi.json_schema.enabled` toggle and `…json_schema.path`. The CLI export
  stays available regardless of HTTP exposure.

The endpoint serves the aggregate only (no per-type HTTP route): one fetch gives a
codegen every type, and the per-type CLI export covers a single-type need. The
aggregate artifact sits beside the per-type directory in the artifact store
(`json-schema/<server>.json` vs the `json-schema/<server>/` dir), so the two never
collide.
