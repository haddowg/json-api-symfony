# Read `belongsToMany` pivot writes from `meta.pivot` (symmetric with reads)

A `belongsToMany` relation's writable pivot values were read, on a write, from a
linkage member's **top-level** `meta` (e.g. `meta: {position: 5}`), and validation
violations pointed at `…/meta/<field>`. But pivot **renders** on reads — and the
OpenAPI request schema **types** the write — at `meta.pivot.<field>` (the linkage
identifier's pivot is namespaced under `pivot` to coexist with system meta such as
`served_by`). So the write runtime contradicted both the read shape and the published
request schema: a client following the spec (`meta: {pivot: {position: 5}}`) had its
pivot values silently ignored.

We now read writable pivot values from `meta.pivot.<field>` on every write path (the
relationship-mutation endpoint and a whole-resource create/update), and emit pivot
validation pointers at `…/meta/pivot/<field>` — so reads, writes, and the OpenAPI
request schema all agree on `meta.pivot`. The change is bundle-only (the persister
reads `meta.pivot`, the validator reads `meta.pivot`, and `JsonPointerBuilder`'s
linkage-meta pointers gain the `pivot` segment); the OpenAPI projection already typed
the request body correctly and is unchanged. The in-memory pivot boundary (no pivot
support) is unchanged.

## Consequences

- **Breaking (pre-1.0):** a client that wrote pivot values at the bare `meta.<field>`
  must move them under `meta.pivot.<field>`. A spec-following client is unaffected —
  it was already sending `meta.pivot` (and previously being silently ignored).
- A non-array or absent `meta.pivot` yields no pivot write (the field keeps its stored
  value on an update, its server default on a create) — the same tolerance the old
  top-level read had.
- This is the write-side completion of ADR 0102 (which made pivot **render** on a
  primary-document linkage): pivot is now consistently `meta.pivot` across read and
  write, on the relationship/related endpoints and in a compound document.
