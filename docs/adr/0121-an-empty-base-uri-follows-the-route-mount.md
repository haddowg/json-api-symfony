# An empty base_uri follows the prefix the server's routes are imported under

Core bases links on the request origin when `base_uri` is empty, and it can't know that
the server's routes were imported under a prefix such as `/api/v5`, so resource links
dropped the prefix. The bundle can work it out: the route loader records the path it
emitted on each route (`_jsonapi_path`), and a decorator on `routing.loader` compares it
with the final, prefixed path once the whole collection is loaded and records the
difference as `_jsonapi_mount`. On each request `ServerProvider::forRequest()` gives core
the effective base `origin + front-controller base path + mount`, through the existing
`Server::withBaseUri()`, and core (from 1.1.1) treats a base's path as the mount, so
document links carry it once.

## Considered options

- **Work the mount out at request time.** Reading the matched route's full path means
  loading the whole route collection on the request, and counting segments of the request
  path breaks on an `{id}` pattern that allows `/`. Recording it while the router compiles
  its cache costs nothing per request.
- **A core seam for the mount.** Not needed: an effective base with a path is already
  what a configured base looks like to core, so core stays unchanged.

## Consequences

- A configured `base_uri` is used verbatim and must include the prefix itself.
- A front controller below the web root (`/app/index.php`) now adds its base path to an
  empty base, even with no prefix, matching the URLs the router generates.
- A hand-written route has no recorded mount unless it sets `_jsonapi_mount` itself.
- The OpenAPI document still advertises no `servers` entry for an empty base, so its
  paths don't show the prefix.
