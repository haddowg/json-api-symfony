# Include safeguards: a `max_include_depth` config default, server wiring, and a safeguard-respecting preloader

Core gained three composing `?include` safeguards (core's own ADRs): a per-relation
`cannotBeIncluded()` opt-out (Capability A), a per-resource / per-server maximum
include depth (Capability B), and a root-scoped allowed-include-paths whitelist
(Capability C) — read off the opt-in `IncludeControlsInterface` via `instanceof`, so
they are fully back-compatible. Core stays **unopinionated**: the server default depth
is `null` (unlimited). The bundle supplies the opinionated piece and threads it through
Symfony's wiring.

We add a single `json_api.max_include_depth` config key (an `integerNode`, **default
`3`**, `min 0`, `0` = unlimited), surfaced as the `haddowg_json_api.max_include_depth`
container parameter and passed into every per-server `ServerFactory`, which calls
`Server::withMaxIncludeDepth()` with the value (`<= 0` resolving to `null`). It mirrors
`json_api.pagination.max_per_page` exactly — same declaration, read helper, and
parameter shape — because it is the same kind of value: an out-of-the-box safety bound
a client cannot exceed, that a resource may still override. We chose `3` so a mutual
**default-include cycle** (A default-includes B, B default-includes A) always terminates
without any per-resource configuration, while still allowing the common `a.b.c` shape; a
resource's own `maxIncludeDepth()` override wins per type.

The reference Doctrine batch include-preloader is made to **respect all three
safeguards**: it never batches a non-includable relation, bounds its recursion by the
same effective depth (resolved once at the root: the resource's `maxIncludeDepth()`
override `??` the server default, normalised so `<= 0` is unlimited), and skips any path
the root's `getAllowedIncludePaths()` excludes. A request that violates a safeguard
already `400`s in core *before* the provider runs, so this is belt-and-braces — but it
is load-bearing for the cycle: without the preloader's own depth bound, a mutual
default-include cycle would recurse the preloader forever even though core would later
cap the rendered tree.

## Consequences

- The safeguards are resolved **once against the root** and threaded unchanged through
  the preloader recursion (a `rootResolved` flag guards the one-time resolution), matching
  core's root-scoped evaluation of the depth cap and the allow-list — they are a property
  of the request's primary resource, not of each hop.
- The functional acceptance is a dual-provider conformance suite
  (`IncludeSafeguardsConformanceTestCase`) over a circular `nodes` chain plus
  `tags`/`roots`/`caps` witnesses, proving on **both** the in-memory and Doctrine-sqlite
  kernels: a `cannotBeIncluded()` relation `400`s; a too-deep `?include` `400`s; the
  default cap of `3` is in force; a mutual default-include cycle terminates; a
  per-resource override wins; and a root whitelist forbids a nested path that is
  includable from its own root.
