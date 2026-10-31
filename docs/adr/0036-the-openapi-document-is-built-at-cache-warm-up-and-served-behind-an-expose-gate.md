# The OpenAPI document is built at cache warm-up and served as an artifact, behind an expose gate

Projecting the document walks the whole registry, so it never happens per request: a cache warmer builds each server's document and JSON Schemas into the cache directory, and the controllers serve the file, building lazily only when it is missing (and writing it back only in debug, so a read-only production filesystem is never written). The HTTP routes exist only when `kernel.debug` or `json_api.openapi.expose_in_prod` is true; the CLI exports are always available, which is why `symfony/console` is a hard dependency.

## Consequences

- Build-time customisation is `OpenApiFactoryInterface` decorators applied inside the document factory, so the warmer, the lazy build and the CLI all see the same document; the highest priority applies last.
- Per-request customisation (`OpenApiServeDecoratorInterface`) runs in the controllers on value objects unserialized from a warmed PHP-serialized artifact, because core's value objects have no `fromArray()` and a hand-maintained hydrator would mirror every one; the bundle prunes what a decorator orphaned. The CLI exports and static `public_path` files stay undecorated, because the committed spec is the full contract for diffing and codegen.
- Cache control on the documentation routes defaults to off, since they are often behind a firewall and a shared cache could hand one user's document to another.
