# Every single write runs in one adapter-owned transaction

A single create, update, delete or relationship write runs in one transaction on its type's persister, opened before the first write step so the body's relationships, hydration, validation and every before hook are inside it, and committed before the after hooks run; an action called with POST, PATCH, PUT or DELETE runs in one on its mount type's persister (else its input type's) through `AfterActionEvent`. A before hook often has to flush early, as when a new row's id is needed before the persister's own write, and without the adapter's transaction an app had to open its own in one hook and commit it in another, which nothing rolled back when a later step threw and which a long-running worker could carry into its next request. There is no opt-out; an Atomic Operations batch keeps owning its transaction, and a persister that can't transact writes as before.

## Consequences

- `rollback()` must undo everything since the matching `beginTransaction()`, including a transaction a hook opened inside it and left open. The Doctrine persister records the connection's nesting level when it begins and rolls back to it even when a failed flush has already closed the `EntityManager`, then clears the manager; it is reset between requests.
- The Doctrine persister's `commit()` no longer flushes, so the hydrated entity of an update a persister accepted for asynchronous processing is not written by the commit. A `202` commits what the before hooks and the persister wrote.
- A message dispatched or an external call made inside the transaction is not undone by a rollback, and a long-running collection action holds one transaction for all its rows.
