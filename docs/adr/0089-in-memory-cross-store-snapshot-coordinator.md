# A snapshot coordinator preserves cross-store object identity on atomic rollback

The in-memory `TransactionalDataPersisterInterface` rolls a batch back by snapshotting and
restoring its `InMemoryStore`. The per-store snapshot deep-cloned each item via a
`serialize(unserialize())` round-trip, which is correct for a single store — but a parent in
store A can reference a related object held in store B (a `mutateRelationship()` wires it), and
the round-trip followed that reference and *cloned* B's object. On restore, store A's parent
pointed at a detached clone, not the live object in B: cross-store identity was severed, so a
post-rollback parent→related read saw stale, disconnected state. This was invisible to the
single-store begin/rollback (each store restored in isolation) but is exactly what the atomic
executor's cross-store rollback hits.

The fix is an `InMemorySnapshotCoordinator` shared by every related store: the first store to
snapshot in a batch captures the item maps of **all** registered stores in ONE `serialize()`
pass, so a shared object reference is encoded once and reconstructed as a single instance
shared across the restored maps; restore deserializes that one graph and hands each store back
its identity-coherent map (rewinding the id counters too), and commit discards it. A store used
in isolation (no coordinator wired) keeps the legacy per-store deep-clone, so nothing outside
the atomic path changes. It is proven by a dual-provider conformance case: a relationship
mutation inside a batch, a forced rollback, then an assertion that both the association *and* the
related-object identity (a parent→related read traversing the restored graph) are back to the
pre-batch state.
