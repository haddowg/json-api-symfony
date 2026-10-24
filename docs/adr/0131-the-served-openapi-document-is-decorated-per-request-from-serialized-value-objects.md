# The served OpenAPI document is decorated per request from serialized value objects

> Update: the pruner, the serialized artifact and the ETag recipe now live in core's `OpenApi\Serve` (core ADR 0159), so every integration prunes and tags the same way. The artifact now records the installed versions of core and this bundle and reads as missing after either changes, and it only unserializes core's value objects, `stdClass`, enums and the classes in `json_api.openapi.serve_allowed_classes`. A document holding any other object gets no artifact: the warmer logs a warning and decorated requests build it.

Build-time decorators can't see the request, so one deployment serving many hosts couldn't give each host its own `servers`, hide a tenant's disabled operations or rewrite example hosts. `OpenApiServeDecoratorInterface` and `JsonSchemaServeDecoratorInterface` now run in the controllers with the `Request`, after the warmer has done the expensive build. The CLI exports and the static `public_path` files stay undecorated, because the committed spec is the full contract for diffing and codegen.

## Considered options

Core's OpenAPI value objects have no `fromArray()`, so a decorated request needs some way back from the warmed artifact to value objects. Adding a hydrator to core would mean a second, hand-maintained mirror of every VO's serialisation, run in PHP on every request. Instead the warmer PHP-serializes the document beside its JSON (`<server>.serve`), and a request unserializes it: about 0.4 ms for a 180 KB document, against 0.3 ms for a bare `json_decode`. No core change was needed. With no serve decorator registered the controller never touches that file and serves the JSON bytes as before.

Decorators only remove things, and the bundle prunes what they orphaned, so app code never walks `$ref`s. Only components reachable from the warmed document's paths are candidates; components it never referenced, or that a decorator added, are kept and act as roots, so a kept component can't end up with a dangling reference. Path items with no operation and tag definitions whose operations all went are dropped. Relationship members whose related type lost its endpoints are left alone: the parent's responses still carry that linkage on the wire, and the document must keep describing it. Security schemes are referenced by name and left alone too.

The ETag has three modes. With no serve decorator it is the artifact's hash, written by the warmer next to the artifact, so a 304 costs one small read. When every decorator of a kind implements `OpenApiServeCacheKeyInterface`, the ETag hashes the artifact hash with each decorator's class and key in order, and `If-None-Match` is checked before anything is loaded or decorated. If any decorator lacks a key the ETag falls back to the hash of the final body, because a partial key would let two different bodies share a tag. Comparison is weak so a CDN's `W/` tag still matches.

`cache_control` is one document-wide key under `json_api.openapi`, like the rest of that block, which has no per-server overrides. It defaults to off, because docs routes are often behind a firewall and a `public` default would let a shared cache hand one user's document to another. No `Vary` is sent: the routes always return JSON and a CDN already keys on the host. The routes are not forced `_stateless`, since an app may legitimately keep them behind a stateful login firewall.

## Consequences

Two decorators with the same priority run in reverse registration order, matching the build-time tag. A cache key doesn't change when a decorator's logic does, so keys should carry a version the author bumps.
