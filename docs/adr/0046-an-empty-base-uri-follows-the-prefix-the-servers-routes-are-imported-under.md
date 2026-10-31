# An empty `base_uri` follows the prefix the server's routes are imported under

With an empty `base_uri` core bases links on the request origin and cannot know that the server's routes were imported under a prefix such as `/api/v5`. The route loader records the path it emitted on each route, a decorator on `routing.loader` compares it with the final prefixed path once the collection is loaded and records the difference as `_jsonapi_mount`, and each request hands core `origin + front-controller base path + mount` as the effective base.

## Considered Options

- Work the mount out at request time: reading the matched route's full path loads the whole route collection per request, and counting request-path segments breaks on an `{id}` pattern that allows `/`. Recording it while the router compiles its cache costs nothing per request.
- A core seam for the mount: unnecessary, since a base with a path is already what a configured base looks like to core.

## Consequences

- A configured `base_uri` is used verbatim and must include the prefix itself.
- A hand-written route has no recorded mount unless it sets `_jsonapi_mount`.
- The OpenAPI document advertises each distinct mount of an empty-base server as a relative `servers` URL, read from the route collection so a warmed document matches a lazily built one; a placeholder prefix without a route default is left out rather than given an invented default.
