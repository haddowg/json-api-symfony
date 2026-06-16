# The Doctrine provider batch-preloads the effective `?include` tree

The reference Doctrine `DataProvider` now batch eager-loads a read's effective
`?include` tree before rendering, so included relationships do not N+1 against the
store. It is Laravel-style: **one query per relation per level, no fetch-joins** —
the `shipmonk/doctrine-entity-preloader` library loads a relation for every source
entity at a level in a single `WHERE id IN (…)`-style query, and the loaded targets
seed the next level. Across the example, `GET /albums?include=tracks` over 16 albums
issues 2 include-load queries (albums + one batched tracks load) instead of the 15 a
lazy render issues.

The preloader reuses **core's** include decision
(`JsonApiRequestInterface::isIncludedRelationship()`, fed each resource's
`getDefaultIncludedRelationships()`), so it preloads exactly the tree the serializer
renders — the explicitly requested `?include`, or the resource's **default-include**
fallback when the request sends none (so a default include is rendered *and*
preloaded; an explicit `?include=` overrides it to nothing). It is alias-aware: the
relation's storage column (`column() ?? name()`) drives the batch, honouring a
`storedAs()` rename.

Preloading is a **pure optimization** — the rendered document is byte-identical with
or without it (a conformance witness proves this by toggling the preloader off). So a
relation the preloader cannot batch silently **falls back to lazy**: a polymorphic
relation (more than one related type — no single target class), a computed /
`extractUsing` / non-association column, a composite-key target (the library does not
support it), and any preloader limitation surfaced as an exception (caught per
relation). The capability is **opt-in**: it is wired only when the optional library
is installed (`suggest` + `require-dev`); without it the provider degrades to lazy
includes. It is also a provider capability (`PreloadsIncludesInterface`), not part of
the core read SPI — a custom provider opts in independently, and one that does not
(the example's `artists` override) renders lazily.

## Consequences

- The handler materializes a collection/page result once (it was already iterating
  it) and passes it to both the preloader and the renderer, so the items are
  traversed once. The to-one related endpoint only preloads when the related type has
  a provider of its own (a to-one related value is read off the parent, so its type
  may not be independently provided).
- `EntityPreloader::preload()` declares its property-name parameter `literal-string`,
  but the bundle passes a runtime association name; a PHPStan stub relaxes that one
  parameter to `string` (correcting a third-party type, not suppressing a real bug).
- The example wires a query-counting DBAL logging middleware purely as a test
  affordance (the `IncludePreloadTest` witness); a production app would not.
